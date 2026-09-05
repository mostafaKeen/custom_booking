<?php
/**
 * Main Booking Widget Iframe Handler (CRM_LEAD_DETAIL_TAB / CRM_DEAL_DETAIL_TAB)
 */
require_once __DIR__ . '/crest.php';
require_once __DIR__ . '/db.php';

// Allow embedding in Bitrix24 iframe
header('X-Frame-Options: ALLOWALL');
header('Content-Security-Policy: frame-ancestors *');

$isWritable = is_writable(__DIR__) || (is_dir(__DIR__ . '/data') && is_writable(__DIR__ . '/data'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bitrix24 Custom Booking Widget</title>
    <!-- Bitrix24 JS SDK -->
    <script src="//api.bitrix24.com/api/v1/"></script>
    <link rel="stylesheet" href="assets/css/widget.css?v=<?=time()?>">
</head>
<body>
    <div class="widget-container">
        <?php if (!$isWritable): ?>
            <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 5px; font-family: sans-serif; font-weight: 500;">
                <strong>Warning:</strong> The application directory is not writable. Settings and logs cannot be saved. Standalone mode will not function properly. Please grant full write permissions to the web server user on the application folder.
            </div>
        <?php endif; ?>
        <!-- Widget Header -->
        <div class="widget-header">
            <h2 class="widget-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 002-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                Appointments & Resource Booking
            </h2>
        </div>

        <!-- Booking Interface Grid -->
        <div class="booking-grid">
            <!-- Left Card: Book New Slot -->
            <div class="card">
                <h3 class="card-title">Schedule New Appointment</h3>
                <form id="booking_form">
                    <input type="hidden" id="service_id" name="service_id" value="1">
                    <input type="hidden" id="staff_id" name="staff_id" value="1">
                    <input type="hidden" id="calendar_target" name="calendar_target" value="user">

                    <div class="form-group">
                        <label for="b24_resource_id">Bitrix24 Booking Resource(s) *</label>
                        <select id="b24_resource_id" name="b24_resource_id[]" class="form-control" required multiple style="height: 85px; padding: 6px;">
                            <option value="699">Driver</option>
                            <option value="701">Meeting Room</option>
                            <option value="703">Photo Grapher</option>
                            <option value="705">Video Grapher</option>
                        </select>
                        <small style="color: #64748b; font-size: 11px; display: block; margin-top: 4px;">Hold Ctrl / Cmd to select multiple.</small>
                    </div>

                    <div class="form-group">
                        <label for="ufCrm29_1787324188722">Booking Type *</label>
                        <select id="ufCrm29_1787324188722" name="ufCrm29_1787324188722[]" class="form-control" required multiple style="height: 75px; padding: 6px;">
                            <option value="685">Resource</option>
                            <option value="687">Viewing</option>
                            <option value="689">Meeting</option>
                        </select>
                    </div>

                    <div class="form-group" id="trip_type_group" style="display: none;">
                        <label for="ufCrm29_1788299065411">Trip Type</label>
                        <select id="ufCrm29_1788299065411" name="ufCrm29_1788299065411" class="form-control">
                            <option value="757">Pick Up & Drop Off</option>
                            <option value="759">Pick Up</option>
                            <option value="761">Drop Off</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Slot Duration *</label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <div style="flex: 1;">
                                <small style="display: block; color: #64748b; margin-bottom: 2px;">Hours</small>
                                <input type="number" id="duration_hours" name="duration_hours" class="form-control" min="0" max="24" value="0" placeholder="0">
                            </div>
                            <div style="flex: 1;">
                                <small style="display: block; color: #64748b; margin-bottom: 2px;">Minutes</small>
                                <input type="number" id="duration_minutes" name="duration_minutes" class="form-control" min="0" max="59" value="30" placeholder="30">
                            </div>
                            <div style="margin-top: 14px;">
                                <button type="button" id="apply_duration_btn" class="btn btn-primary" style="padding: 8px 14px; white-space: nowrap; height: 38px;">Apply</button>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="booking_date">Appointment Date</label>
                        <input type="date" id="booking_date" name="booking_date" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Available Time Slots</label>
                        <div id="slots_container" class="slots-container">
                            <!-- Dynamically loaded slot buttons -->
                        </div>
                    </div>



                    <div class="form-group">
                        <label for="client_name">Client Name</label>
                        <input type="text" id="client_name" name="client_name" class="form-control" placeholder="John Doe">
                    </div>

                    <div class="form-group">
                        <label for="client_phone">Phone / WhatsApp</label>
                        <input type="text" id="client_phone" name="client_phone" class="form-control" placeholder="+123456789">
                    </div>

                    <div class="form-group" id="transfer_from_group">
                        <label for="ufCrm29_1788553737348">Transfer From (Address)</label>
                        <input type="text" id="ufCrm29_1788553737348" name="ufCrm29_1788553737348" class="form-control" placeholder="Pickup Address / Location">
                    </div>

                    <div class="form-group" id="transfer_to_group">
                        <label for="ufCrm29_1788553748580">Transfer To (Address)</label>
                        <input type="text" id="ufCrm29_1788553748580" name="ufCrm29_1788553748580" class="form-control" placeholder="Drop-off Address / Destination">
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes / Requirements</label>
                        <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="Optional notes for appointment..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                        Confirm & Save Booking
                    </button>
                </form>
            </div>

            <!-- Right Card: Entity Booking History & Calendar View -->
            <div class="card" id="bookings_card">
                <h3 class="card-title">
                    <span id="bookings_card_title">Associated Bookings</span>
                    <div class="view-toggle" id="view_toggle" style="display:none;">
                        <button type="button" class="toggle-btn active" id="btn_calendar_view" onclick="switchView('calendar')">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            Calendar
                        </button>
                        <button type="button" class="toggle-btn" id="btn_list_view" onclick="switchView('list')">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                            List
                        </button>
                    </div>
                </h3>

                <!-- Calendar View (shown in standalone mode) -->
                <div id="calendar_view" style="display:none;">
                    <!-- Filter Controls & Legend -->
                    <div class="calendar-filters" style="display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 12px; background: #f8fafc; padding: 10px 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <div>
                                <label style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 2px; display: block;">Status Filter</label>
                                <select id="cal_filter_status" onchange="applyCalendarFilters()" class="form-control" style="font-size: 12px; padding: 4px 8px; height: 32px;">
                                    <option value="ALL">All Statuses</option>
                                    <option value="REQUESTED">🟡 Requested (Yellow)</option>
                                    <option value="RESERVED">🟢 Reserved & Approved (Green)</option>
                                    <option value="CANCELED">🔴 Canceled (Red)</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 2px; display: block;">Resource Filter</label>
                                <select id="cal_filter_resource" onchange="applyCalendarFilters()" class="form-control" style="font-size: 12px; padding: 4px 8px; height: 32px;">
                                    <option value="ALL">All Resources</option>
                                    <option value="699">Driver</option>
                                    <option value="701">Meeting Room</option>
                                    <option value="703">Photo Grapher</option>
                                    <option value="705">Video Grapher</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 2px; display: block;">Driver Filter</label>
                                <select id="cal_filter_driver" onchange="applyCalendarFilters()" class="form-control" style="font-size: 12px; padding: 4px 8px; height: 32px;">
                                    <option value="ALL">All Drivers</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 2px; display: block;">Photographer Filter</label>
                                <select id="cal_filter_photographer" onchange="applyCalendarFilters()" class="form-control" style="font-size: 12px; padding: 4px 8px; height: 32px;">
                                    <option value="ALL">All Photographers</option>
                                </select>
                            </div>
                        </div>

                        <!-- Legend -->
                        <div style="display: flex; gap: 12px; align-items: center; font-size: 11px; font-weight: 600;">
                            <span style="display: inline-flex; align-items: center; gap: 4px;">
                                <span style="width: 10px; height: 10px; border-radius: 50%; background: #facc15; border: 1px solid #ca8a04;"></span>
                                Requested (Pending)
                            </span>
                            <span style="display: inline-flex; align-items: center; gap: 4px;">
                                <span style="width: 10px; height: 10px; border-radius: 50%; background: #22c55e; border: 1px solid #15803d;"></span>
                                Reserved (Approved)
                            </span>
                            <span style="display: inline-flex; align-items: center; gap: 4px;">
                                <span style="width: 10px; height: 10px; border-radius: 50%; background: #ef4444; border: 1px solid #b91c1c;"></span>
                                Canceled
                            </span>
                        </div>
                    </div>

                    <!-- Calendar navigation bar -->
                    <div class="calendar-nav">
                        <button type="button" class="cal-nav-btn" id="cal_prev" onclick="calNavigate(-1)">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                        </button>
                        <span class="cal-nav-title" id="cal_nav_title">Today</span>
                        <button type="button" class="cal-nav-btn" id="cal_next" onclick="calNavigate(1)">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </button>
                        <button type="button" class="cal-nav-today-btn" onclick="calGoToday()">Today</button>
                    </div>

                    <!-- Mini month calendar -->
                    <div class="calendar-mini-month" id="mini_month_calendar"></div>

                    <!-- Resource calendar grid -->
                    <div class="calendar-grid-wrapper" id="calendar_grid_wrapper">
                        <!-- Dynamically rendered by JS -->
                    </div>
                </div>

                <!-- List View (shown in entity mode or toggle) -->
                <div id="list_view">
                    <div id="bookings_list" class="booking-list">
                        <!-- Dynamically populated bookings list -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/widget.js?v=<?php echo time(); ?>"></script>
</body>
</html>
