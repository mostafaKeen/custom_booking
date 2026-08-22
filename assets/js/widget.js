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
    if (typeof BX24 !== 'undefined') {
        BX24.init(function() {
            const placement = BX24.placement.info();
            if (placement && placement.options) {
                // Determine Entity Type
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
        });
    } else {
        // Fallback for standalone/local testing
        const urlParams = new URLSearchParams(window.location.search);
        placementInfo.entityType = urlParams.get('entity_type') || 'LEAD';
        placementInfo.entityId = parseInt(urlParams.get('entity_id') || '1', 10);
        loadEntityBookings();
    }
}

function loadServicesAndStaff() {
    fetch('api.php?action=get_services_and_staff')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const serviceSelect = document.getElementById('service_id');
                const staffSelect = document.getElementById('staff_id');

                serviceSelect.innerHTML = '';
                data.services.forEach(s => {
                    serviceSelect.innerHTML += `<option value="${s.id}">${s.name} (${s.duration_minutes}m - $${s.price})</option>`;
                });

                staffSelect.innerHTML = '';
                data.staff.forEach(st => {
                    staffSelect.innerHTML += `<option value="${st.id}">${st.name}</option>`;
                });

                // Set default date to today and load slots
                document.getElementById('booking_date').value = new Date().toISOString().split('T')[0];
                loadSlots();
            }
        });
}

function loadSlots() {
    const serviceId = document.getElementById('service_id').value;
    const staffId = document.getElementById('staff_id').value;
    const date = document.getElementById('booking_date').value;

    if (!serviceId || !staffId || !date) return;

    fetch(`api.php?action=get_slots&service_id=${serviceId}&staff_id=${staffId}&date=${date}`)
        .then(res => res.json())
        .then(data => {
            const slotsContainer = document.getElementById('slots_container');
            slotsContainer.innerHTML = '';
            selectedSlot = null;

            if (data.status === 'success' && data.slots.length > 0) {
                data.slots.forEach(slot => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `slot-btn ${slot.available ? '' : 'disabled'}`;
                    btn.textContent = slot.display.split('-')[0].trim();
                    
                    if (slot.available) {
                        btn.onclick = function() {
                            document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
                            btn.classList.add('selected');
                            selectedSlot = slot.start_time;
                        };
                    } else {
                        btn.disabled = true;
                    }
                    slotsContainer.appendChild(btn);
                });
            } else {
                slotsContainer.innerHTML = '<p class="text-muted" style="font-size:13px;">No available slots for this date.</p>';
            }
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

        const formData = new FormData(this);
        formData.append('action', 'create_booking');
        formData.append('entity_type', placementInfo.entityType);
        formData.append('entity_id', placementInfo.entityId);
        formData.append('start_time', selectedSlot);

        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                alert('Booking created successfully!');
                document.getElementById('notes').value = '';
                loadSlots();
                loadEntityBookings();
            } else {
                alert('Error creating booking: ' + data.message);
            }
        });
    });
}

function loadEntityBookings() {
    fetch(`api.php?action=get_entity_bookings&entity_type=${placementInfo.entityType}&entity_id=${placementInfo.entityId}`)
        .then(res => res.json())
        .then(data => {
            const list = document.getElementById('bookings_list');
            list.innerHTML = '';

            if (data.status === 'success' && data.bookings.length > 0) {
                data.bookings.forEach(b => {
                    const statusClass = 'status-' + b.status.toLowerCase();
                    const item = document.createElement('div');
                    item.className = 'booking-item';
                    item.innerHTML = `
                        <div class="booking-item-header">
                            <span class="service-badge" style="background-color: ${b.service_color || '#2563eb'}">${b.service_name}</span>
                            <span class="status-pill ${statusClass}">${b.status}</span>
                        </div>
                        <div class="booking-details">
                            <strong>Date:</strong> ${b.booking_date} (${b.start_time} - ${b.end_time})<br>
                            <strong>Staff:</strong> ${b.staff_name}<br>
                            <strong>Client:</strong> ${b.client_name || 'N/A'} (${b.client_phone || 'N/A'})<br>
                            <strong>Target Calendar:</strong> ${b.calendar_target}
                        </div>
                        <div class="booking-actions">
                            <button class="btn btn-outline btn-sm" onclick="updateStatus(${b.id}, 'Confirmed')">Confirm</button>
                            <button class="btn btn-outline btn-sm" onclick="updateStatus(${b.id}, 'Completed')">Complete</button>
                            <button class="btn btn-outline btn-sm" onclick="updateStatus(${b.id}, 'Cancelled')">Cancel</button>
                        </div>
                    `;
                    list.appendChild(item);
                });
            } else {
                list.innerHTML = '<p class="text-muted" style="font-size:13px; text-align:center; padding:20px;">No bookings found for this record.</p>';
            }

            if (typeof BX24 !== 'undefined') {
                BX24.resizeWindow(document.body.scrollWidth, Math.max(document.body.scrollHeight, 650));
            }
        });
}

function updateStatus(bookingId, status) {
    if (!confirm(`Are you sure you want to mark this booking as ${status}?`)) return;

    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('booking_id', bookingId);
    formData.append('status', status);

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            loadEntityBookings();
            loadSlots();
        } else {
            alert('Error updating status: ' + data.message);
        }
    });
}
