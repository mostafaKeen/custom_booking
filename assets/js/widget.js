/**
 * Custom Bitrix24 Booking Widget Frontend Engine
 */
let placementInfo = {
    entityType: 'LEAD',
    entityId: 0
};
let selectedSlot = null;

document.addEventListener('DOMContentLoaded', function() {
    initBX24();
    loadServicesAndStaff();
    setupEventListeners();
});

function initBX24() {
    // BX24 JS SDK only works inside Bitrix24 iframe.
    // When opened directly in a browser, BX24 will be undefined or throw an error.
    if (typeof BX24 === 'undefined' || BX24 === null) {
        console.warn('BX24 JS SDK not available. Running in standalone/debug mode.');
        initStandaloneMode();
        return;
    }

    try {
        BX24.init(function() {
            try {
                var placement = BX24.placement.info();
                if (placement && placement.options) {
                    // Determine Entity Type from placement code
                    if (placement.placement === 'CRM_DEAL_DETAIL_TAB') {
                        placementInfo.entityType = 'DEAL';
                    } else {
                        placementInfo.entityType = 'LEAD';
                    }
                    placementInfo.entityId = placement.options.ID || placement.options.id || 0;
                }

                // Adjust iframe size automatically
                BX24.resizeWindow(document.body.scrollWidth, Math.max(document.body.scrollHeight, 650));
                loadEntityBookings();
            } catch(e) {
                console.error('BX24 placement.info() error:', e);
                initStandaloneMode();
            }
        });
    } catch(e) {
        console.error('BX24.init() failed:', e);
        initStandaloneMode();
    }
}

function initStandaloneMode() {
    // Fallback for standalone/debug or when BX24 SDK not in iframe context
    var urlParams = new URLSearchParams(window.location.search);
    placementInfo.entityType = urlParams.get('entity_type') || 'LEAD';
    placementInfo.entityId = parseInt(urlParams.get('entity_id') || '1', 10);
    loadEntityBookings();
}

function loadServicesAndStaff() {
    fetch('api.php?action=get_services_and_staff')
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                var serviceSelect = document.getElementById('service_id');
                var staffSelect = document.getElementById('staff_id');

                serviceSelect.innerHTML = '';
                data.services.forEach(function(s) {
                    serviceSelect.innerHTML += '<option value="' + s.id + '">' + s.name + ' (' + s.duration_minutes + 'm - $' + s.price + ')</option>';
                });

                staffSelect.innerHTML = '';
                data.staff.forEach(function(st) {
                    staffSelect.innerHTML += '<option value="' + st.id + '">' + st.name + '</option>';
                });

                // Set default date to today and load slots
                document.getElementById('booking_date').value = new Date().toISOString().split('T')[0];
                loadSlots();
            }
        })
        .catch(function(err) {
            console.error('Failed to load services/staff:', err);
        });
}

function loadSlots() {
    var serviceId = document.getElementById('service_id').value;
    var staffId = document.getElementById('staff_id').value;
    var date = document.getElementById('booking_date').value;

    if (!serviceId || !staffId || !date) return;

    fetch('api.php?action=get_slots&service_id=' + serviceId + '&staff_id=' + staffId + '&date=' + date)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            var slotsContainer = document.getElementById('slots_container');
            slotsContainer.innerHTML = '';
            selectedSlot = null;

            if (data.status === 'success' && data.slots.length > 0) {
                data.slots.forEach(function(slot) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'slot-btn ' + (slot.available ? '' : 'disabled');
                    btn.textContent = slot.display.split('-')[0].trim();

                    if (slot.available) {
                        btn.onclick = function() {
                            document.querySelectorAll('.slot-btn').forEach(function(b) { b.classList.remove('selected'); });
                            btn.classList.add('selected');
                            selectedSlot = slot.start_time;
                        };
                    } else {
                        btn.disabled = true;
                    }
                    slotsContainer.appendChild(btn);
                });
            } else {
                slotsContainer.innerHTML = '<p style="font-size:13px; color:#64748b;">No available slots for this date.</p>';
            }
        })
        .catch(function(err) {
            console.error('Failed to load slots:', err);
        });
}

function setupEventListeners() {
    document.getElementById('service_id').addEventListener('change', loadSlots);
    document.getElementById('staff_id').addEventListener('change', loadSlots);
    document.getElementById('booking_date').addEventListener('change', loadSlots);

    document.getElementById('booking_form').addEventListener('submit', function(e) {
        e.preventDefault();
        if (!selectedSlot) {
            alert('Please select an available time slot.');
            return;
        }

        var formData = new FormData(this);
        formData.append('action', 'create_booking');
        formData.append('entity_type', placementInfo.entityType);
        formData.append('entity_id', placementInfo.entityId);
        formData.append('start_time', selectedSlot);

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                alert('Booking created successfully!');
                document.getElementById('notes').value = '';
                loadSlots();
                loadEntityBookings();
            } else {
                alert('Error creating booking: ' + data.message);
            }
        })
        .catch(function(err) {
            console.error('Failed to create booking:', err);
        });
    });
}

function loadEntityBookings() {
    fetch('api.php?action=get_entity_bookings&entity_type=' + placementInfo.entityType + '&entity_id=' + placementInfo.entityId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            var list = document.getElementById('bookings_list');
            list.innerHTML = '';

            if (data.status === 'success' && data.bookings.length > 0) {
                data.bookings.forEach(function(b) {
                    var statusClass = 'status-' + b.status.toLowerCase();
                    var item = document.createElement('div');
                    item.className = 'booking-item';
                    item.innerHTML =
                        '<div class="booking-item-header">' +
                            '<span class="service-badge" style="background-color: ' + (b.service_color || '#2563eb') + '">' + b.service_name + '</span>' +
                            '<span class="status-pill ' + statusClass + '">' + b.status + '</span>' +
                        '</div>' +
                        '<div class="booking-details">' +
                            '<strong>Date:</strong> ' + b.booking_date + ' (' + b.start_time + ' - ' + b.end_time + ')<br>' +
                            '<strong>Staff:</strong> ' + b.staff_name + '<br>' +
                            '<strong>Client:</strong> ' + (b.client_name || 'N/A') + ' (' + (b.client_phone || 'N/A') + ')<br>' +
                            '<strong>Target Calendar:</strong> ' + b.calendar_target +
                        '</div>' +
                        '<div class="booking-actions">' +
                            '<button class="btn btn-outline btn-sm" onclick="updateStatus(' + b.id + ', \'Confirmed\')">Confirm</button>' +
                            '<button class="btn btn-outline btn-sm" onclick="updateStatus(' + b.id + ', \'Completed\')">Complete</button>' +
                            '<button class="btn btn-outline btn-sm" onclick="updateStatus(' + b.id + ', \'Cancelled\')">Cancel</button>' +
                        '</div>';
                    list.appendChild(item);
                });
            } else {
                list.innerHTML = '<p style="font-size:13px; text-align:center; padding:20px; color:#64748b;">No bookings found for this record.</p>';
            }

            // Resize Bitrix24 iframe if available
            if (typeof BX24 !== 'undefined' && BX24 !== null) {
                try {
                    BX24.resizeWindow(document.body.scrollWidth, Math.max(document.body.scrollHeight, 650));
                } catch(e) {}
            }
        })
        .catch(function(err) {
            console.error('Failed to load bookings:', err);
        });
}

function updateStatus(bookingId, status) {
    if (!confirm('Are you sure you want to mark this booking as ' + status + '?')) return;

    var formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('booking_id', bookingId);
    formData.append('status', status);

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            loadEntityBookings();
            loadSlots();
        } else {
            alert('Error updating status: ' + data.message);
        }
    })
    .catch(function(err) {
        console.error('Failed to update status:', err);
    });
}
