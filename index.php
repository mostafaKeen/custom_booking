<?php
/**
 * Main Booking Widget Iframe Handler (CRM_LEAD_DETAIL_TAB / CRM_DEAL_DETAIL_TAB)
 */
require_once __DIR__ . '/crest.php';
require_once __DIR__ . '/db.php';

// Allow embedding in Bitrix24 iframe
header('X-Frame-Options: ALLOWALL');
header('Content-Security-Policy: frame-ancestors *');
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
                    <div class="form-group">
                        <label for="service_id">Service / Appointment Type</label>
                        <select id="service_id" name="service_id" class="form-control" required></select>
                    </div>

                    <div class="form-group">
                        <label for="staff_id">Assigned Specialist (Local)</label>
                        <select id="staff_id" name="staff_id" class="form-control" required></select>
                    </div>

                    <div class="form-group">
                        <label for="b24_resource_id">Bitrix24 Booking Resource(s) *</label>
                        <select id="b24_resource_id" name="b24_resource_id[]" class="form-control" required multiple style="height: 85px; padding: 6px;">
                            <!-- Dynamically loaded resources from Bitrix24 -->
                        </select>
                        <small style="color: #64748b; font-size: 11px; display: block; margin-top: 4px;">Hold Ctrl / Cmd to select multiple.</small>
                    </div>

                    <div class="form-group">
                        <label for="calendar_target">Calendar Destination Sync</label>
                        <select id="calendar_target" name="calendar_target" class="form-control">
                            <option value="user">My Calendar</option>
                            <option value="company_calendar">Public (Company Calendar)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="ufCrm29_1787324188722">Booking Type *</label>
                        <select id="ufCrm29_1787324188722" name="ufCrm29_1787324188722[]" class="form-control" required multiple style="height: 75px; padding: 6px;">
                            <option value="685">Resource</option>
                            <option value="687">Viewing</option>
                            <option value="689">Meeting</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="ufCrm29_1787324769682">Car Reserved *</label>
                        <select id="ufCrm29_1787324769682" name="ufCrm29_1787324769682" class="form-control" required>
                            <option value="707">No Car</option>
                            <option value="709">Car 1</option>
                            <option value="711">Car 2</option>
                            <option value="713">Car 3</option>
                        </select>
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

    <script src="assets/js/widget.js"></script>
</body>
</html>
