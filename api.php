<?php
/**
 * AJAX API Handler for Custom Booking Widget
 */
header('Content-Type: application/json');
require_once __DIR__ . '/crest.php';
require_once __DIR__ . '/db.php';

$action = $_REQUEST['action'] ?? '';
$db = DB::getConnection();

function sendJson($data) {
    echo json_encode($data);
    exit;
}

try {
    switch ($action) {
        case 'get_services_and_staff':
            $services = $db->query("SELECT * FROM services ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $staff = $db->query("SELECT * FROM staff WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            
            // Optionally fetch native Bitrix24 online booking resources via REST API
            $b24Resources = [];
            $res = CRest::call('booking.v1.resource.list', []);
            if (!empty($res['result']['resources'])) {
                $b24Resources = $res['result']['resources'];
            } else {
                $res = CRest::call('calendar.resource.list', []);
                if (!empty($res['result'])) {
                    $b24Resources = $res['result'];
                }
            }

            sendJson([
                'status' => 'success',
                'services' => $services,
                'staff' => $staff,
                'b24_resources' => $b24Resources
            ]);
            break;

        case 'get_slots':
            $date = $_REQUEST['date'] ?? date('Y-m-d');
            $serviceId = (int)($_REQUEST['service_id'] ?? 0);
            $staffId = (int)($_REQUEST['staff_id'] ?? 0);

            // Fetch service duration
            $stmt = $db->prepare("SELECT duration_minutes FROM services WHERE id = ?");
            $stmt->execute([$serviceId]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            $duration = $service ? (int)$service['duration_minutes'] : DEFAULT_SLOT_DURATION;

            // Fetch staff working hours
            $stmt = $db->prepare("SELECT working_start, working_end FROM staff WHERE id = ?");
            $stmt->execute([$staffId]);
            $staffInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            $workStart = $staffInfo['working_start'] ?? DEFAULT_WORKING_START;
            $workEnd = $staffInfo['working_end'] ?? DEFAULT_WORKING_END;

            // Fetch existing bookings for this staff on this date
            $stmt = $db->prepare("SELECT start_time, end_time FROM bookings WHERE staff_id = ? AND booking_date = ? AND status != 'Cancelled'");
            $stmt->execute([$staffId, $date]);
            $existingBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Generate time slots
            $slots = [];
            $currentSec = strtotime($date . ' ' . $workStart);
            $endSec = strtotime($date . ' ' . $workEnd);

            while ($currentSec + ($duration * 60) <= $endSec) {
                $slotStartStr = date('H:i:s', $currentSec);
                $slotEndStr = date('H:i:s', $currentSec + ($duration * 60));
                $slotDisplay = date('h:i A', $currentSec) . ' - ' . date('h:i A', $currentSec + ($duration * 60));

                // Check clash with existing bookings
                $isClash = false;
                foreach ($existingBookings as $b) {
                    $bStart = strtotime($date . ' ' . $b['start_time']);
                    $bEnd = strtotime($date . ' ' . $b['end_time']);
                    if ($currentSec < $bEnd && ($currentSec + ($duration * 60)) > $bStart) {
                        $isClash = true;
                        break;
                    }
                }

                $slots[] = [
                    'start_time' => $slotStartStr,
                    'end_time' => $slotEndStr,
                    'display' => $slotDisplay,
                    'available' => !$isClash
                ];

                $currentSec += ($duration + DEFAULT_BUFFER_TIME) * 60;
            }

            sendJson(['status' => 'success', 'slots' => $slots, 'date' => $date]);
            break;

        case 'get_entity_bookings':
            $entityType = $_REQUEST['entity_type'] ?? 'LEAD';
            $entityId = (int)($_REQUEST['entity_id'] ?? 0);

            $stmt = $db->prepare("SELECT b.*, s.name as service_name, s.color as service_color, st.name as staff_name 
                                  FROM bookings b 
                                  LEFT JOIN services s ON b.service_id = s.id 
                                  LEFT JOIN staff st ON b.staff_id = st.id 
                                  WHERE b.entity_type = ? AND b.entity_id = ? 
                                  ORDER BY b.booking_date DESC, b.start_time DESC");
            $stmt->execute([$entityType, $entityId]);
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendJson(['status' => 'success', 'bookings' => $bookings]);
            break;

        case 'create_booking':
            $entityType = strtoupper($_REQUEST['entity_type'] ?? 'LEAD');
            $entityId = (int)($_REQUEST['entity_id'] ?? 0);
            $serviceId = (int)($_REQUEST['service_id'] ?? 0);
            $staffId = (int)($_REQUEST['staff_id'] ?? 0);
            $bookingDate = $_REQUEST['booking_date'] ?? date('Y-m-d');
            $startTime = $_REQUEST['start_time'] ?? '09:00:00';
            $calendarTarget = $_REQUEST['calendar_target'] ?? 'responsible'; // 'responsible', 'shared', or 'native_resource'
            $clientName = trim($_REQUEST['client_name'] ?? '');
            $clientPhone = trim($_REQUEST['client_phone'] ?? '');
            $clientEmail = trim($_REQUEST['client_email'] ?? '');
            $notes = trim($_REQUEST['notes'] ?? '');

            // Calculate end time based on service duration
            $stmt = $db->prepare("SELECT name, duration_minutes FROM services WHERE id = ?");
            $stmt->execute([$serviceId]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            $serviceName = $service ? $service['name'] : 'Appointment';
            $duration = $service ? (int)$service['duration_minutes'] : 30;

            $startTs = strtotime($bookingDate . ' ' . $startTime);
            $endTs = $startTs + ($duration * 60);
            $endTime = date('H:i:s', $endTs);

            // Fetch staff details
            $stmt = $db->prepare("SELECT name, b24_user_id FROM staff WHERE id = ?");
            $stmt->execute([$staffId]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);
            $staffName = $staff ? $staff['name'] : 'Specialist';
            $b24UserId = $staff ? (int)$staff['b24_user_id'] : 1;

            // 1. Insert into local DB
            $stmt = $db->prepare("INSERT INTO bookings (entity_type, entity_id, client_name, client_phone, client_email, service_id, staff_id, booking_date, start_time, end_time, status, calendar_target, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Scheduled', ?, ?)");
            $stmt->execute([
                $entityType, $entityId, $clientName, $clientPhone, $clientEmail,
                $serviceId, $staffId, $bookingDate, $startTime, $endTime, $calendarTarget, $notes
            ]);
            $bookingId = $db->lastInsertId();

            // 2. Fetch Entity owner in B24 to target calendar if responsible
            $ownerId = $b24UserId;
            if ($entityType === 'LEAD' && $entityId > 0) {
                $leadRes = CRest::call('crm.lead.get', ['id' => $entityId]);
                if (!empty($leadRes['result']['ASSIGNED_BY_ID'])) {
                    $ownerId = (int)$leadRes['result']['ASSIGNED_BY_ID'];
                }
            } elseif ($entityType === 'DEAL' && $entityId > 0) {
                $dealRes = CRest::call('crm.deal.get', ['id' => $entityId]);
                if (!empty($dealRes['result']['ASSIGNED_BY_ID'])) {
                    $ownerId = (int)$dealRes['result']['ASSIGNED_BY_ID'];
                }
            }

            // 3. Create Bitrix24 CRM Timeline Activity (crm.activity.add)
            $activitySubject = "Booking: {$serviceName} with {$staffName}";
            $activityDesc = "Appointment Details:\n"
                . "Service: {$serviceName}\n"
                . "Date & Time: {$bookingDate} " . date('h:i A', $startTs) . " - " . date('h:i A', $endTs) . "\n"
                . "Client: {$clientName} ({$clientPhone})\n"
                . "Specialist: {$staffName}\n"
                . "Notes: {$notes}";

            $bindings = [];
            if ($entityType === 'LEAD') {
                $bindings[] = ['OWNER_TYPE_ID' => 1, 'OWNER_ID' => $entityId];
            } else {
                $bindings[] = ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => $entityId];
            }

            $activityRes = CRest::call('crm.activity.add', [
                'fields' => [
                    'SUBJECT' => $activitySubject,
                    'DESCRIPTION' => $activityDesc,
                    'START_TIME' => date('c', $startTs),
                    'END_TIME' => date('c', $endTs),
                    'COMPLETED' => 'N',
                    'RESPONSIBLE_ID' => $ownerId,
                    'BINDINGS' => $bindings,
                    'TYPE_ID' => 2, // Meeting
                ]
            ]);

            $b24ActivityId = $activityRes['result'] ?? 0;

            // 4. Create Bitrix24 Calendar Event (calendar.event.add)
            $b24CalendarEventId = 0;
            $targetOwner = ($calendarTarget === 'shared') ? DEFAULT_SHARED_CALENDAR_ID : $ownerId;

            $calRes = CRest::call('calendar.event.add', [
                'type' => 'user',
                'ownerId' => $targetOwner,
                'name' => "Appointment: {$serviceName} - {$clientName}",
                'description' => $activityDesc,
                'from' => date('d.m.Y H:i:s', $startTs),
                'to' => date('d.m.Y H:i:s', $endTs),
                'skip_time' => 'N',
                'section' => 0
            ]);
            $b24CalendarEventId = $calRes['result'] ?? 0;

            // 5. Sync to Bitrix24 Native Online Booking Grid (/booking/) via booking.v1.booking.add
            $b24NativeBookingId = 0;
            $nativeRes = CRest::call('booking.v1.booking.add', [
                'name' => "{$serviceName} - {$clientName}",
                'dateFrom' => date('Y-m-d\TH:i:sP', $startTs),
                'dateTo' => date('Y-m-d\TH:i:sP', $endTs),
                'notes' => $notes,
                'clients' => [
                    [
                        'type' => ($entityType === 'LEAD' ? 'CRM_LEAD' : 'CRM_DEAL'),
                        'id' => $entityId,
                        'name' => $clientName,
                        'phone' => $clientPhone,
                        'email' => $clientEmail
                    ]
                ]
            ]);
            if (!empty($nativeRes['result']['id'])) {
                $b24NativeBookingId = $nativeRes['result']['id'];
            }

            // Update booking record with B24 IDs
            $stmt = $db->prepare("UPDATE bookings SET b24_activity_id = ?, b24_calendar_event_id = ? WHERE id = ?");
            $stmt->execute([$b24ActivityId, $b24CalendarEventId, $bookingId]);

            // 6. Send Notification to Staff (im.notification.add)
            if ($b24UserId > 0) {
                CRest::call('im.notification.add', [
                    'TO' => $b24UserId,
                    'MESSAGE' => "New Booking Scheduled: {$serviceName} for {$clientName} on {$bookingDate} at " . date('h:i A', $startTs)
                ]);
            }

            sendJson([
                'status' => 'success',
                'message' => 'Booking created successfully!',
                'booking_id' => $bookingId,
                'activity_id' => $b24ActivityId,
                'calendar_event_id' => $b24CalendarEventId,
                'native_booking_id' => $b24NativeBookingId
            ]);
            break;

        case 'update_status':
            $bookingId = (int)($_REQUEST['booking_id'] ?? 0);
            $newStatus = $_REQUEST['status'] ?? 'Scheduled';

            $stmt = $db->prepare("UPDATE bookings SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$newStatus, $bookingId]);

            sendJson(['status' => 'success', 'message' => 'Status updated to ' . $newStatus]);
            break;

        default:
            sendJson(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    sendJson(['status' => 'error', 'message' => $e->getMessage()]);
}
