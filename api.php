<?php
/**
 * AJAX API Handler for Custom Booking Widget with Comprehensive Logging
 */
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once __DIR__ . '/crest.php';
require_once __DIR__ . '/db.php';

$action = $_REQUEST['action'] ?? '';
$db = DB::getConnection();

function writeLog($stage, $data = []) {
    $logDir = __DIR__ . '/data';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/booking_debug.log';
    $time = date('Y-m-d H:i:s');
    $content = "[{$time}] [{$stage}] " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL . str_repeat('-', 80) . PHP_EOL;
    file_put_contents($logFile, $content, FILE_APPEND);
}

function sendJson($data) {
    echo json_encode($data);
    exit;
}

function enrichBookingsWithSpaData($bookings) {
    if (empty($bookings)) return [];
    
    // Collect all spa item IDs
    $spaIds = [];
    foreach ($bookings as $b) {
        if (!empty($b['b24_spa_item_id'])) {
            $spaIds[] = (int)$b['b24_spa_item_id'];
        }
    }

    $spaDataMap = [];
    $userCache = [];

    if (!empty($spaIds)) {
        // Query Bitrix24 SPA items
        $res = CRest::call('crm.item.list', [
            'entityTypeId' => 1088,
            'filter' => ['@id' => $spaIds],
            'select' => ['id', 'stageId', 'ufCrm29_1788295852', 'ufCrm29_1788416337', 'ufCrm29_1787324769682']
        ]);

        if (!empty($res['result']['items'])) {
            foreach ($res['result']['items'] as $item) {
                $itemId = (int)$item['id'];
                $stageId = $item['stageId'] ?? '';
                $driverId = (int)($item['ufCrm29_1788295852'] ?? 0);
                $photographerId = (int)($item['ufCrm29_1788416337'] ?? 0);
                $carId = $item['ufCrm29_1787324769682'] ?? '';

                // Resolve user names if IDs exist
                $driverName = '';
                $photographerName = '';

                if ($driverId > 0) {
                    if (!isset($userCache[$driverId])) {
                        $uRes = CRest::call('user.get', ['ID' => $driverId]);
                        $userCache[$driverId] = !empty($uRes['result'][0]) ? trim(($uRes['result'][0]['NAME'] ?? '') . ' ' . ($uRes['result'][0]['LAST_NAME'] ?? '')) : "Employee #{$driverId}";
                    }
                    $driverName = $userCache[$driverId];
                }

                if ($photographerId > 0) {
                    if (!isset($userCache[$photographerId])) {
                        $uRes = CRest::call('user.get', ['ID' => $photographerId]);
                        $userCache[$photographerId] = !empty($uRes['result'][0]) ? trim(($uRes['result'][0]['NAME'] ?? '') . ' ' . ($uRes['result'][0]['LAST_NAME'] ?? '')) : "Employee #{$photographerId}";
                    }
                    $photographerName = $userCache[$photographerId];
                }

                $spaDataMap[$itemId] = [
                    'stageId' => $stageId,
                    'driverId' => $driverId,
                    'driverName' => $driverName,
                    'photographerId' => $photographerId,
                    'photographerName' => $photographerName,
                    'carId' => $carId
                ];
            }
        }
    }

    foreach ($bookings as &$b) {
        $spaId = (int)($b['b24_spa_item_id'] ?? 0);
        if ($spaId > 0 && isset($spaDataMap[$spaId])) {
            $spaInfo = $spaDataMap[$spaId];
            if (!empty($spaInfo['stageId'])) {
                $b['status'] = $spaInfo['stageId'];
            }
            $b['driver_id'] = $spaInfo['driverId'];
            $b['driver_name'] = $spaInfo['driverName'];
            $b['photographer_id'] = $spaInfo['photographerId'];
            $b['photographer_name'] = $spaInfo['photographerName'];
            $b['car_id'] = $spaInfo['carId'];
        } else {
            $b['driver_id'] = 0;
            $b['driver_name'] = '';
            $b['photographer_id'] = 0;
            $b['photographer_name'] = '';
            $b['car_id'] = '';
        }
    }
    unset($b);

    return $bookings;
}

writeLog("API_REQUEST_START", [
    'action' => $action,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'request_params' => $_REQUEST
]);

try {
    switch ($action) {
        case 'search_crm_entities':
            $type = strtolower($_REQUEST['type'] ?? 'lead');
            $query = $_REQUEST['query'] ?? '';
            $start = (int)($_REQUEST['start'] ?? 0);
            $results = [];
            $next = null;

            $filter = [];
            if (!empty($query)) {
                $filter['%TITLE'] = $query;
            }

            if ($type === 'lead') {
                $res = CRest::call('crm.lead.list', [
                    'filter' => $filter,
                    'select' => ['ID', 'TITLE', 'NAME', 'LAST_NAME', 'PHONE'],
                    'order' => ['ID' => 'DESC'],
                    'start' => $start
                ]);
                writeLog("SEARCH_CRM_LEADS_RESULT", ['query' => $query, 'start' => $start, 'res' => $res]);
                $next = $res['next'] ?? null;
                if (!empty($res['result']) && is_array($res['result'])) {
                    foreach ($res['result'] as $item) {
                        $phone = '';
                        if (!empty($item['PHONE']) && is_array($item['PHONE'])) {
                            $phone = $item['PHONE'][0]['VALUE'] ?? '';
                        }
                        $results[] = [
                            'id' => (int)$item['ID'],
                            'title' => $item['TITLE'] ?? ('Lead #' . $item['ID']),
                            'name' => trim(($item['NAME'] ?? '') . ' ' . ($item['LAST_NAME'] ?? '')),
                            'phone' => $phone
                        ];
                    }
                }
            } else if ($type === 'deal') {
                $res = CRest::call('crm.deal.list', [
                    'filter' => $filter,
                    'select' => ['ID', 'TITLE', 'CONTACT_ID'],
                    'order' => ['ID' => 'DESC'],
                    'start' => $start
                ]);
                writeLog("SEARCH_CRM_DEALS_RESULT", ['query' => $query, 'start' => $start, 'res' => $res]);
                $next = $res['next'] ?? null;
                if (!empty($res['result']) && is_array($res['result'])) {
                    foreach ($res['result'] as $item) {
                        $contactId = (int)($item['CONTACT_ID'] ?? 0);
                        $clientName = '';
                        $clientPhone = '';
                        if ($contactId > 0) {
                            $contactRes = CRest::call('crm.contact.get', ['id' => $contactId]);
                            if (!empty($contactRes['result'])) {
                                $clientName = trim(($contactRes['result']['NAME'] ?? '') . ' ' . ($contactRes['result']['LAST_NAME'] ?? ''));
                                if (!empty($contactRes['result']['PHONE']) && is_array($contactRes['result']['PHONE'])) {
                                    $clientPhone = $contactRes['result']['PHONE'][0]['VALUE'] ?? '';
                                }
                            }
                        }
                        $results[] = [
                            'id' => (int)$item['ID'],
                            'title' => $item['TITLE'] ?? ('Deal #' . $item['ID']),
                            'name' => $clientName,
                            'phone' => $clientPhone
                        ];
                    }
                }
            }
            sendJson(['status' => 'success', 'results' => $results, 'next' => $next]);
            break;

        case 'get_services_and_staff':
            $services = $db->query("SELECT * FROM services ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $staff = $db->query("SELECT * FROM staff WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch native Bitrix24 online booking resources via REST API
            $b24Resources = [];
            $res = CRest::call('booking.v1.resource.list', []);
            writeLog("REST_CALL_booking.v1.resource.list", $res);

            if (!empty($res['result']['resource'])) {
                $b24Resources = $res['result']['resource'];
            } elseif (!empty($res['result']['resources'])) {
                $b24Resources = $res['result']['resources'];
            } else {
                $resCal = CRest::call('calendar.resource.list', []);
                writeLog("REST_CALL_calendar.resource.list", $resCal);
                if (!empty($resCal['result'])) {
                    $b24Resources = $resCal['result'];
                }
            }

            // Fetch dynamic SPA enumeration fields from entityTypeId 1088
            $carOptions = [];
            $bookingTypeOptions = [];
            $resourceOptions = [];
            $tripTypeOptions = [];
            $spaFieldsRes = CRest::call('crm.item.fields', ['entityTypeId' => 1088]);
            writeLog("REST_CALL_crm.item.fields_1088", $spaFieldsRes);

            if (!empty($spaFieldsRes['result']['fields'])) {
                foreach ($spaFieldsRes['result']['fields'] as $key => $field) {
                    $cleanKey = strtolower(str_replace('_', '', $key));
                    if ($cleanKey === 'ufcrm291787324769682') { // Car Reserved
                        if (!empty($field['items']) && is_array($field['items'])) {
                            $carOptions = $field['items'];
                        }
                    } elseif ($cleanKey === 'ufcrm291787324188722') { // Booking Type
                        if (!empty($field['items']) && is_array($field['items'])) {
                            $bookingTypeOptions = $field['items'];
                        }
                    } elseif ($cleanKey === 'ufcrm291787324656') { // Resources
                        if (!empty($field['items']) && is_array($field['items'])) {
                            $resourceOptions = $field['items'];
                        }
                    } elseif ($cleanKey === 'ufcrm291788299065411') { // Trip Type
                        if (!empty($field['items']) && is_array($field['items'])) {
                            $tripTypeOptions = $field['items'];
                        }
                    }
                }
            }

            // Fallback default options if offline or not returned
            if (empty($carOptions)) {
                $carOptions = [
                    ['ID' => 707, 'VALUE' => 'No Car'],
                    ['ID' => 709, 'VALUE' => 'Toyota Fortuner'],
                    ['ID' => 711, 'VALUE' => 'Tahoe'],
                    ['ID' => 713, 'VALUE' => 'Range Rover'],
                    ['ID' => 755, 'VALUE' => 'MB Viano']
                ];
            }
            if (empty($bookingTypeOptions)) {
                $bookingTypeOptions = [
                    ['ID' => 685, 'VALUE' => 'Resource'],
                    ['ID' => 687, 'VALUE' => 'Viewing'],
                    ['ID' => 689, 'VALUE' => 'Meeting']
                ];
            }
            if (empty($resourceOptions)) {
                $resourceOptions = [
                    ['ID' => 699, 'VALUE' => 'Driver'],
                    ['ID' => 701, 'VALUE' => 'Meeting Room'],
                    ['ID' => 703, 'VALUE' => 'Photo Grapher'],
                    ['ID' => 705, 'VALUE' => 'Video Grapher']
                ];
            }
            if (empty($tripTypeOptions)) {
                $tripTypeOptions = [
                    ['ID' => 757, 'VALUE' => 'Pick Up & Drop Off'],
                    ['ID' => 759, 'VALUE' => 'Pick Up'],
                    ['ID' => 761, 'VALUE' => 'Drop Off']
                ];
            }

            sendJson([
                'status' => 'success',
                'services' => $services,
                'staff' => $staff,
                'b24_resources' => $b24Resources,
                'car_options' => $carOptions,
                'booking_type_options' => $bookingTypeOptions,
                'resource_options' => $resourceOptions,
                'trip_type_options' => $tripTypeOptions
            ]);
            break;

        case 'get_slots':
            $date = $_REQUEST['date'] ?? date('Y-m-d');
            $serviceId = (int)($_REQUEST['service_id'] ?? 1);
            $staffId = (int)($_REQUEST['staff_id'] ?? 1);

            // Allow custom duration passed from frontend, or fallback to service duration
            $customDuration = (int)($_REQUEST['duration_minutes'] ?? 0);
            if ($customDuration > 0) {
                $duration = $customDuration;
            } else {
                $stmt = $db->prepare("SELECT duration_minutes FROM services WHERE id = ?");
                $stmt->execute([$serviceId]);
                $service = $stmt->fetch(PDO::FETCH_ASSOC);
                $duration = $service ? (int)$service['duration_minutes'] : DEFAULT_SLOT_DURATION;
            }

            // Fetch staff working hours
            $stmt = $db->prepare("SELECT working_start, working_end FROM staff WHERE id = ?");
            $stmt->execute([$staffId]);
            $staffInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            $workStart = $staffInfo['working_start'] ?? DEFAULT_WORKING_START;
            $workEnd = $staffInfo['working_end'] ?? DEFAULT_WORKING_END;

            // Fetch existing bookings for this staff on this date
            $stmt = $db->prepare("SELECT start_time, end_time FROM bookings WHERE staff_id = ? AND booking_date = ? AND status != 'Cancelled' AND status != 'DT1088_37:FAIL'");
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

                // Check count of existing overlapping bookings for overbooking indicator
                $overlapCount = 0;
                foreach ($existingBookings as $b) {
                    $bStart = strtotime($date . ' ' . $b['start_time']);
                    $bEnd = strtotime($date . ' ' . $b['end_time']);
                    if ($currentSec < $bEnd && ($currentSec + ($duration * 60)) > $bStart) {
                        $overlapCount++;
                    }
                }

                // Overbooking is enabled: slots are ALWAYS available!
                $slots[] = [
                    'start_time' => $slotStartStr,
                    'end_time' => $slotEndStr,
                    'display' => $slotDisplay,
                    'available' => true,
                    'is_occupied' => ($overlapCount > 0),
                    'overlap_count' => $overlapCount
                ];

                $currentSec += ($duration + DEFAULT_BUFFER_TIME) * 60;
            }

            sendJson(['status' => 'success', 'slots' => $slots, 'date' => $date]);
            break;

        case 'get_all_bookings':
            $stmt = $db->query("SELECT b.*, s.name as service_name, s.color as service_color, st.name as staff_name 
                                  FROM bookings b 
                                  LEFT JOIN services s ON b.service_id = s.id 
                                  LEFT JOIN staff st ON b.staff_id = st.id 
                                  ORDER BY b.booking_date DESC, b.start_time DESC");
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $bookings = enrichBookingsWithSpaData($bookings);
            sendJson(['status' => 'success', 'bookings' => $bookings]);
            break;

        case 'get_calendar_bookings':
            $startDate = $_REQUEST['start_date'] ?? date('Y-m-d');
            $endDate = $_REQUEST['end_date'] ?? date('Y-m-d');

            $stmt = $db->prepare("SELECT b.*, s.name as service_name, s.color as service_color, st.name as staff_name 
                                  FROM bookings b 
                                  LEFT JOIN services s ON b.service_id = s.id 
                                  LEFT JOIN staff st ON b.staff_id = st.id 
                                  WHERE b.booking_date BETWEEN ? AND ? 
                                  ORDER BY b.booking_date ASC, b.start_time ASC");
            $stmt->execute([$startDate, $endDate]);
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $bookings = enrichBookingsWithSpaData($bookings);

            // Fetch B24 resources for column headers
            $b24Resources = [];
            $res = CRest::call('booking.v1.resource.list', []);
            if (!empty($res['result']['resource'])) {
                $b24Resources = $res['result']['resource'];
            } elseif (!empty($res['result']['resources'])) {
                $b24Resources = $res['result']['resources'];
            } else {
                $resCal = CRest::call('calendar.resource.list', []);
                if (!empty($resCal['result'])) {
                    $b24Resources = $resCal['result'];
                }
            }

            sendJson([
                'status' => 'success',
                'bookings' => $bookings,
                'resources' => $b24Resources,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
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

            // Auto-fetch client name & phone from Lead or Deal Contact
            $clientName = '';
            $clientPhone = '';

            if ($entityId > 0) {
                if ($entityType === 'LEAD') {
                    $crmRes = CRest::call('crm.lead.get', ['id' => $entityId]);
                    if (!empty($crmRes['result'])) {
                        $clientName = trim(($crmRes['result']['NAME'] ?? '') . ' ' . ($crmRes['result']['LAST_NAME'] ?? ''));
                        if (!empty($crmRes['result']['PHONE']) && is_array($crmRes['result']['PHONE'])) {
                            $clientPhone = $crmRes['result']['PHONE'][0]['VALUE'] ?? '';
                        }
                    }
                } elseif ($entityType === 'DEAL') {
                    $crmRes = CRest::call('crm.deal.get', ['id' => $entityId]);
                    if (!empty($crmRes['result'])) {
                        if (!empty($crmRes['result']['CONTACT_ID'])) {
                            $contactRes = CRest::call('crm.contact.get', ['id' => $crmRes['result']['CONTACT_ID']]);
                            if (!empty($contactRes['result'])) {
                                $clientName = trim(($contactRes['result']['NAME'] ?? '') . ' ' . ($contactRes['result']['LAST_NAME'] ?? ''));
                                if (!empty($contactRes['result']['PHONE']) && is_array($contactRes['result']['PHONE'])) {
                                    $clientPhone = $contactRes['result']['PHONE'][0]['VALUE'] ?? '';
                                }
                            }
                        }
                    }
                }
            }

            sendJson([
                'status' => 'success', 
                'bookings' => $bookings,
                'client_name' => $clientName,
                'client_phone' => $clientPhone
            ]);
            break;

        case 'create_booking':
            $entityType = strtoupper($_REQUEST['entity_type'] ?? 'LEAD');
            $entityId = (int)($_REQUEST['entity_id'] ?? 0);
            $serviceId = (int)($_REQUEST['service_id'] ?? 0);
            $staffId = (int)($_REQUEST['staff_id'] ?? 0);
            
            // Handle multiple resources input
            $b24ResourceIdInput = $_REQUEST['b24_resource_id'] ?? [];
            $resourceIds = [];
            if (is_array($b24ResourceIdInput)) {
                foreach ($b24ResourceIdInput as $resId) {
                    if ((int)$resId > 0) {
                        $resourceIds[] = (int)$resId;
                    }
                }
            } else {
                if ((int)$b24ResourceIdInput > 0) {
                    $resourceIds[] = (int)$b24ResourceIdInput;
                }
            }

            $bookingDate = $_REQUEST['booking_date'] ?? date('Y-m-d');
            $startTime = $_REQUEST['start_time'] ?? '09:00:00';
            $calendarTarget = $_REQUEST['calendar_target'] ?? 'user';
            $clientName = trim($_REQUEST['client_name'] ?? '');
            $clientPhone = trim($_REQUEST['client_phone'] ?? '');
            $clientEmail = trim($_REQUEST['client_email'] ?? '');
            $notes = trim($_REQUEST['notes'] ?? '');

            writeLog("CREATE_BOOKING_START", [
                'entityType' => $entityType,
                'entityId' => $entityId,
                'serviceId' => $serviceId,
                'staffId' => $staffId,
                'resourceIds' => $resourceIds,
                'bookingDate' => $bookingDate,
                'startTime' => $startTime,
                'calendarTarget' => $calendarTarget,
                'clientName' => $clientName
            ]);

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

            // Step 1: Fetch Entity owner, Title & linked Contact in B24
            $ownerId = $b24UserId;
            $contactId = 0;
            $entityTitle = '';
            if ($entityType === 'LEAD' && $entityId > 0) {
                $leadRes = CRest::call('crm.lead.get', ['id' => $entityId]);
                writeLog("STEP_2_CRM_LEAD_GET", $leadRes);
                if (!empty($leadRes['result']['ASSIGNED_BY_ID'])) {
                    $ownerId = (int)$leadRes['result']['ASSIGNED_BY_ID'];
                }
                if (!empty($leadRes['result']['CONTACT_ID'])) {
                    $contactId = (int)$leadRes['result']['CONTACT_ID'];
                }
                if (!empty($leadRes['result']['TITLE'])) {
                    $entityTitle = $leadRes['result']['TITLE'];
                }
                // Auto fill client details if empty
                if (empty($clientName)) {
                    $clientName = trim(($leadRes['result']['NAME'] ?? '') . ' ' . ($leadRes['result']['LAST_NAME'] ?? ''));
                }
                if (empty($clientPhone) && !empty($leadRes['result']['PHONE']) && is_array($leadRes['result']['PHONE'])) {
                    $clientPhone = $leadRes['result']['PHONE'][0]['VALUE'] ?? '';
                }
            } elseif ($entityType === 'DEAL' && $entityId > 0) {
                $dealRes = CRest::call('crm.deal.get', ['id' => $entityId]);
                writeLog("STEP_2_CRM_DEAL_GET", $dealRes);
                if (!empty($dealRes['result']['ASSIGNED_BY_ID'])) {
                    $ownerId = (int)$dealRes['result']['ASSIGNED_BY_ID'];
                }
                if (!empty($dealRes['result']['CONTACT_ID'])) {
                    $contactId = (int)$dealRes['result']['CONTACT_ID'];
                }
                if (!empty($dealRes['result']['TITLE'])) {
                    $entityTitle = $dealRes['result']['TITLE'];
                }
                if ($contactId > 0) {
                    $contactRes = CRest::call('crm.contact.get', ['id' => $contactId]);
                    if (empty($clientName) && !empty($contactRes['result'])) {
                        $clientName = trim(($contactRes['result']['NAME'] ?? '') . ' ' . ($contactRes['result']['LAST_NAME'] ?? ''));
                    }
                    if (empty($clientPhone) && !empty($contactRes['result']['PHONE']) && is_array($contactRes['result']['PHONE'])) {
                        $clientPhone = $contactRes['result']['PHONE'][0]['VALUE'] ?? '';
                    }
                }
            }

            if (empty($entityTitle)) {
                if ($entityType === 'NONE' || $entityId == 0) {
                    $entityTitle = 'Standalone Booking';
                } else {
                    $entityTitle = ($entityType === 'LEAD' ? 'Lead' : 'Deal') . ' #' . $entityId;
                }
            }

            // Retrieve custom fields from request
            $bookingType = $_REQUEST['ufCrm29_1787324188722'] ?? [];
            $tripType = $_REQUEST['ufCrm29_1788299065411'] ?? '';

            // Fetch the name of the Bitrix24 user who is creating this booking
            $createdByName = '';
            try {
                $userRes = CRest::call('user.current', []);
                if (!empty($userRes['result'])) {
                    $createdByName = trim(($userRes['result']['NAME'] ?? '') . ' ' . ($userRes['result']['LAST_NAME'] ?? ''));
                }
            } catch (Exception $e) {
                writeLog('USER_CURRENT_ERROR', ['error' => $e->getMessage()]);
            }

            // Use selected resource IDs directly
            $resourcesList = [];
            if (!empty($resourceIds)) {
                foreach ($resourceIds as $rId) {
                    $resourcesList[] = (int)$rId;
                }
            }

            // Enforce Driver Trip Type business rule
            $isDriverSelected = in_array(699, $resourcesList);
            if ($isDriverSelected) {
                if (empty($tripType)) {
                    $tripType = 757; // Default to Pick Up & Drop Off
                }
            } else {
                $tripType = 0; // Not visible/applicable when Driver is not selected
            }

            // Step 2: Insert into local DB
            $stmt = $db->prepare("INSERT INTO bookings (entity_type, entity_id, entity_title, client_name, client_phone, client_email, service_id, staff_id, booking_date, start_time, end_time, status, calendar_target, notes, ufCrm29_1787324188722, ufCrm29_1787324656, ufCrm29_1788299065411, created_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'DT1088_37:NEW', ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $entityType, $entityId, $entityTitle, $clientName, $clientPhone, $clientEmail,
                $serviceId, $staffId, $bookingDate, $startTime, $endTime, $calendarTarget, $notes,
                is_array($bookingType) ? implode(',', $bookingType) : $bookingType,
                is_array($resourcesList) ? implode(',', $resourcesList) : $resourcesList,
                (int)$tripType,
                $createdByName
            ]);
            $bookingId = $db->lastInsertId();
            writeLog("STEP_1_LOCAL_DB_INSERT_SUCCESS", ['booking_id' => $bookingId, 'entity_title' => $entityTitle, 'created_by' => $createdByName]);

            // Step 3: Create CRM Activity (crm.activity.add) with COMMUNICATIONS
            $activitySubject = "Booking: {$serviceName} with {$staffName}";
            $activityDesc = "Appointment Details:\n"
                . "Service: {$serviceName}\n"
                . "Date & Time: {$bookingDate} " . date('h:i A', $startTs) . " - " . date('h:i A', $endTs) . "\n"
                . "Client: {$clientName} ({$clientPhone})\n"
                . "Specialist: {$staffName}\n"
                . "Notes: {$notes}";

            $b24ActivityId = 0;
            if ($entityId > 0 && $entityType !== 'NONE') {
                $bindings = [];
                $communications = [];

                if ($entityType === 'LEAD') {
                    $bindings[] = ['OWNER_TYPE_ID' => 1, 'OWNER_ID' => $entityId];
                    if (!empty($clientPhone)) {
                        $communications[] = [
                            'VALUE' => $clientPhone,
                            'ENTITY_TYPE_ID' => 1,
                            'ENTITY_ID' => $entityId,
                            'TYPE' => 'PHONE'
                        ];
                    }
                } else {
                    $bindings[] = ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => $entityId];
                    if (!empty($clientPhone)) {
                        $communications[] = [
                            'VALUE' => $clientPhone,
                            'ENTITY_TYPE_ID' => 2,
                            'ENTITY_ID' => $entityId,
                            'TYPE' => 'PHONE'
                        ];
                    }
                }

                $activityFields = [
                    'SUBJECT' => $activitySubject,
                    'DESCRIPTION' => $activityDesc,
                    'START_TIME' => date('c', $startTs),
                    'END_TIME' => date('c', $endTs),
                    'COMPLETED' => 'N',
                    'RESPONSIBLE_ID' => $ownerId,
                    'BINDINGS' => $bindings,
                    'TYPE_ID' => 2, // Meeting
                ];

                if (!empty($communications)) {
                    $activityFields['COMMUNICATIONS'] = $communications;
                }

                $activityRes = CRest::call('crm.activity.add', ['fields' => $activityFields]);
                writeLog("STEP_3_CRM_ACTIVITY_ADD", $activityRes);
                $b24ActivityId = $activityRes['result'] ?? 0;
            }

            // Step 4: Create Calendar Event (user calendar or company calendar)
            $b24CalendarEventId = 0;

            // Determine calendar type and owner ID based on target
            if ($calendarTarget === 'user') {
                $calType = 'user';
                $currentUserRes = CRest::call('user.current', []);
                writeLog("STEP_4_USER_CURRENT", $currentUserRes);
                $calOwnerId = !empty($currentUserRes['result']['ID']) ? (int)$currentUserRes['result']['ID'] : $ownerId;
            } else {
                $calType = 'company_calendar';
                $calOwnerId = 0;
            }

            // Retrieve section ID dynamically to avoid permission/invalid section issues
            $calSectionId = 0;
            $sectionsRes = CRest::call('calendar.section.get', [
                'type' => $calType,
                'ownerId' => $calOwnerId
            ]);
            writeLog("STEP_4_CALENDAR_SECTION_GET", $sectionsRes);
            if (!empty($sectionsRes['result']) && is_array($sectionsRes['result'])) {
                $calSectionId = (int)$sectionsRes['result'][0]['ID'];
            }

            $eventParams = [
                'type' => $calType,
                'ownerId' => $calOwnerId,
                'name' => "Appointment: {$serviceName} - {$clientName}",
                'description' => $activityDesc,
                'from' => date('d.m.Y H:i:s', $startTs),
                'to' => date('d.m.Y H:i:s', $endTs),
                'skip_time' => 'N',
                'private_event' => 'N',
            ];

            if ($entityId > 0 && $entityType !== 'NONE') {
                $crmLink = ($entityType === 'LEAD' ? 'L_' : 'D_') . $entityId;
                $eventParams['crm_fields'] = [$crmLink];
            }

            if ($calSectionId > 0) {
                $eventParams['section'] = $calSectionId;
            } else {
                $eventParams['auto_detect_section'] = 'Y';
            }

            $calRes = CRest::call('calendar.event.add', $eventParams);
            writeLog("STEP_4_CALENDAR_EVENT_ADD", $calRes);
            $b24CalendarEventId = $calRes['result'] ?? 0;

            // Step 5: Sync to Native Online Booking (/booking/) via booking.v1.booking.add
            $b24NativeBookingId = 0;

            if (empty($resourceIds)) {
                // Fallback to fetch first active resource
                $resList = CRest::call('booking.v1.resource.list', []);
                writeLog("STEP_5_RESOURCE_LIST_CHECK", $resList);

                $resourceArray = $resList['result']['resource'] ?? ($resList['result']['resources'] ?? ($resList['result'] ?? []));
                if (is_array($resourceArray)) {
                    foreach ($resourceArray as $resItem) {
                        if (!empty($resItem['id'])) {
                            $resourceIds[] = (int)$resItem['id'];
                            break;
                        } elseif (!empty($resItem['ID'])) {
                            $resourceIds[] = (int)$resItem['ID'];
                            break;
                        }
                    }
                }
            }

            if (!empty($resourceIds)) {
                $nativeRes = CRest::call('booking.v1.booking.add', [
                    'fields' => [
                        'name' => "{$serviceName} - {$clientName}",
                        'description' => $notes,
                        'resourceIds' => $resourceIds,
                        'datePeriod' => [
                            'from' => [
                                'timestamp' => $startTs,
                                'timezone' => 'Asia/Dubai'
                            ],
                            'to' => [
                                'timestamp' => $endTs,
                                'timezone' => 'Asia/Dubai'
                            ]
                        ]
                    ]
                ]);
                writeLog("STEP_5_BOOKING_V1_BOOKING_ADD", $nativeRes);

                if (!empty($nativeRes['result']['id'])) {
                    $b24NativeBookingId = $nativeRes['result']['id'];
                } elseif (!empty($nativeRes['result']) && is_numeric($nativeRes['result'])) {
                    $b24NativeBookingId = $nativeRes['result'];
                }

                // Call client.set if Contact ID is resolved
                if ($b24NativeBookingId > 0 && $contactId > 0) {
                    $clientSetRes = CRest::call('booking.v1.booking.client.set', [
                        'bookingId' => $b24NativeBookingId,
                        'clients' => [
                            [
                                'id' => $contactId,
                                'type' => [
                                    'module' => 'crm',
                                    'code' => 'CONTACT'
                                ]
                            ]
                        ]
                    ]);
                    writeLog("STEP_5_BOOKING_CLIENT_SET", $clientSetRes);
                }

                // Call externalData.set to link to the source Lead or Deal
                if ($b24NativeBookingId > 0 && $entityId > 0 && $entityType !== 'NONE') {
                    $extDataSetRes = CRest::call('booking.v1.booking.externalData.set', [
                        'bookingId' => $b24NativeBookingId,
                        'externalData' => [
                            [
                                'moduleId' => 'crm',
                                'entityTypeId' => ($entityType === 'LEAD' ? 'LEAD' : 'DEAL'),
                                'value' => (string)$entityId
                            ]
                        ]
                    ]);
                    writeLog("STEP_5_BOOKING_EXTERNAL_DATA_SET", $extDataSetRes);
                }
            } else {
                writeLog("STEP_5_BOOKING_V1_BOOKING_ADD_SKIPPED", ['reason' => 'No active resourceIds selected or found']);
            }

            // Step 5b: Create SPA Item (Smart Process Automation) linked to Booking (entityTypeId 1088)
            $b24SpaItemId = 0;
            $spaFields = [
                'title' => "Booking: {$serviceName} - {$clientName}",
                'assignedById' => $ownerId,
                'stageId' => 'DT1088_37:NEW'
            ];
            if ($entityType === 'LEAD') {
                $spaFields['parentId1'] = $entityId;
            } elseif ($entityType === 'DEAL') {
                $spaFields['parentId2'] = $entityId;
            }

            // Map custom fields to correct dynamic types (enumerations/integer arrays/integers)
            if (is_array($bookingType)) {
                $spaFields['ufCrm29_1787324188722'] = array_map('intval', $bookingType);
            } elseif (!empty($bookingType)) {
                $spaFields['ufCrm29_1787324188722'] = [intval($bookingType)];
            }

            if (is_array($resourcesList)) {
                $spaFields['ufCrm29_1787324656'] = array_map('intval', $resourcesList);
            } elseif (!empty($resourcesList)) {
                $spaFields['ufCrm29_1787324656'] = [intval($resourcesList)];
            }

            if (!empty($tripType) && (int)$tripType > 0) {
                $spaFields['ufCrm29_1788299065411'] = intval($tripType);
            }

            $spaRes = CRest::call('crm.item.add', [
                'entityTypeId' => 1088,
                'fields' => $spaFields
            ]);
            writeLog("STEP_5B_SPA_ITEM_ADD", $spaRes);
            $b24SpaItemId = $spaRes['result']['item']['id'] ?? 0;

            // Update local DB with B24 IDs
            $stmt = $db->prepare("UPDATE bookings SET b24_activity_id = ?, b24_calendar_event_id = ?, b24_spa_item_id = ? WHERE id = ?");
            $stmt->execute([$b24ActivityId, $b24CalendarEventId, $b24SpaItemId, $bookingId]);

            // Step 6: Send Staff Notification (im.notify.system.add)
            if ($b24UserId > 0) {
                $notifRes = CRest::call('im.notify.system.add', [
                    'USER_ID' => $b24UserId,
                    'MESSAGE' => "New Booking Scheduled: {$serviceName} for {$clientName} on {$bookingDate} at " . date('h:i A', $startTs)
                ]);
                writeLog("STEP_6_IM_NOTIFICATION_ADD", $notifRes);
            }

            $response = [
                'status' => 'success',
                'message' => 'Booking created successfully!',
                'booking_id' => $bookingId,
                'activity_id' => $b24ActivityId,
                'calendar_event_id' => $b24CalendarEventId,
                'native_booking_id' => $b24NativeBookingId
            ];
            writeLog("CREATE_BOOKING_COMPLETE", $response);
            sendJson($response);
            break;

        case 'update_status':
            $bookingId = (int)($_REQUEST['booking_id'] ?? 0);
            $newStatus = $_REQUEST['status'] ?? 'DT1088_37:NEW';

            $stmt = $db->prepare("UPDATE bookings SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$newStatus, $bookingId]);

            // Fetch b24_spa_item_id from DB
            $stmt = $db->prepare("SELECT b24_spa_item_id FROM bookings WHERE id = ?");
            $stmt->execute([$bookingId]);
            $b24SpaItemId = (int)$stmt->fetchColumn();

            if ($b24SpaItemId > 0) {
                $spaUpdateRes = CRest::call('crm.item.update', [
                    'entityTypeId' => 1088,
                    'id' => $b24SpaItemId,
                    'fields' => [
                        'stageId' => $newStatus
                    ]
                ]);
                writeLog("UPDATE_SPA_STAGE", ['booking_id' => $bookingId, 'spa_item_id' => $b24SpaItemId, 'res' => $spaUpdateRes, 'stage' => $newStatus]);
            }

            writeLog("UPDATE_STATUS", ['booking_id' => $bookingId, 'new_status' => $newStatus]);
            sendJson(['status' => 'success', 'message' => 'Status updated to ' . $newStatus]);
            break;

        default:
            writeLog("UNKNOWN_ACTION", ['action' => $action]);
            sendJson(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    writeLog("API_EXCEPTION_ERROR", [
        'error_message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    sendJson(['status' => 'error', 'message' => $e->getMessage()]);
}
