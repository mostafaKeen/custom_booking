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

function getAuthParams() {
    let params = '';
    if (typeof BX24 !== 'undefined' && BX24 !== null) {
        try {
            const auth = BX24.getAuth();
            if (auth && auth.access_token) {
                params += '&AUTH_ID=' + encodeURIComponent(auth.access_token);
            }
            if (auth && auth.refresh_token) {
                params += '&REFRESH_ID=' + encodeURIComponent(auth.refresh_token);
            }
            if (auth && auth.domain) {
                params += '&DOMAIN=' + encodeURIComponent(auth.domain);
            }
        } catch (e) {}
    }
    return params;
}

function initBX24() {
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
                    if (placement.placement === 'CRM_DEAL_DETAIL_TAB') {
                        placementInfo.entityType = 'DEAL';
                        placementInfo.entityId = placement.options.ID || placement.options.id || 0;
                    } else if (placement.placement === 'CRM_LEAD_DETAIL_TAB') {
                        placementInfo.entityType = 'LEAD';
                        placementInfo.entityId = placement.options.ID || placement.options.id || 0;
                    } else {
                        // Opened from direct link or custom app list
                        placementInfo.entityId = 0;
                    }
                } else {
                    placementInfo.entityId = 0;
                }

                BX24.resizeWindow(document.body.scrollWidth, Math.max(document.body.scrollHeight, 650));
                
                if (placementInfo.entityId == 0) {
                    adjustLayoutForStandalone();
                } else {
                    loadEntityBookings();
                }
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
    var urlParams = new URLSearchParams(window.location.search);
    placementInfo.entityType = urlParams.get('entity_type') || '';
    placementInfo.entityId = parseInt(urlParams.get('entity_id') || '0', 10);

    if (placementInfo.entityId == 0) {
        adjustLayoutForStandalone();
    } else {
        loadEntityBookings();
    }
}

function adjustLayoutForStandalone() {
    // Hide Left Form Column (since we are not inside a specific Lead/Deal card)
    const formCard = document.getElementById('booking_form').closest('.card');
    if (formCard) {
        formCard.style.display = 'none';
    }

    // Make Right Bookings Column Full Width
    const grid = document.querySelector('.booking-grid');
    if (grid) {
        grid.style.gridTemplateColumns = '1fr';
    }

    // Change title of bookings card
    const bookingsTitle = document.querySelector('.booking-grid .card:last-child .card-title');
    if (bookingsTitle) {
        bookingsTitle.innerHTML = 'All Scheduled Appointments';
    }

    loadAllBookings();
}

function loadServicesAndStaff() {
    fetch('api.php?action=get_services_and_staff' + getAuthParams())
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

                var b24ResourceSelect = document.getElementById('b24_resource_id');
                b24ResourceSelect.innerHTML = '';
                if (data.b24_resources && data.b24_resources.length > 0) {
                    data.b24_resources.forEach(function(r) {
                        b24ResourceSelect.innerHTML += '<option value="' + r.id + '">' + r.name + '</option>';
                    });
                } else {
                    b24ResourceSelect.innerHTML = '<option value="">No Active Bitrix24 Resources</option>';
                }

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

    fetch('api.php?action=get_slots&service_id=' + serviceId + '&staff_id=' + staffId + '&date=' + date + getAuthParams())
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

        const postUrl = 'api.php?' + getAuthParams().substring(1);

        fetch(postUrl, {
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

function loadAllBookings() {
    fetch('api.php?action=get_all_bookings' + getAuthParams())
        .then(function(res) { return res.json(); })
        .then(function(data) {
            renderBookingsList(data);
        })
        .catch(function(err) {
            console.error('Failed to load all bookings:', err);
        });
}

function loadEntityBookings() {
    fetch('api.php?action=get_entity_bookings&entity_type=' + placementInfo.entityType + '&entity_id=' + placementInfo.entityId + getAuthParams())
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                if (data.client_name && !document.getElementById('client_name').value) {
                    document.getElementById('client_name').value = data.client_name;
                }
                if (data.client_phone && !document.getElementById('client_phone').value) {
                    document.getElementById('client_phone').value = data.client_phone;
                }
            }
            renderBookingsList(data);
        })
        .catch(function(err) {
            console.error('Failed to load bookings:', err);
        });
}

function renderBookingsList(data) {
    var list = document.getElementById('bookings_list');
    list.innerHTML = '';

    if (data.status === 'success' && data.bookings.length > 0) {
        data.bookings.forEach(function(b) {
            var statusClass = 'status-' + b.status.toLowerCase();
            var item = document.createElement('div');
            item.className = 'booking-item';
            
            var crmBadge = '';
            if (b.entity_id > 0) {
                var domain = 'capitalwestern.bitrix24.com';
                if (typeof BX24 !== 'undefined' && BX24 !== null) {
                    try {
                        var auth = BX24.getAuth();
                        if (auth && auth.domain) {
                            domain = auth.domain;
                        }
                    } catch(e) {}
                }
                var entityPath = b.entity_type.toLowerCase() === 'lead' ? 'lead' : 'deal';
                var entityUrl = 'https://' + domain + '/crm/' + entityPath + '/details/' + b.entity_id + '/';
                var displayTitle = b.entity_title || (b.entity_type + ' #' + b.entity_id);
                crmBadge = '<a href="' + entityUrl + '" target="_blank" style="font-size:11px; font-weight:bold; background:#eff6ff; color:#2563eb; text-decoration:none; padding:3px 8px; border-radius:4px; border:1px solid #bfdbfe; margin-right:6px; display:inline-flex; align-items:center; gap:4px;">' + 
                    '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg> ' +
                    displayTitle + '</a>';
            }

            item.innerHTML =
                '<div class="booking-item-header">' +
                    '<div>' +
                        '<span class="service-badge" style="background-color: ' + (b.service_color || '#2563eb') + '">' + b.service_name + '</span>' +
                        crmBadge +
                    '</div>' +
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
        list.innerHTML = '<p style="font-size:13px; text-align:center; padding:20px; color:#64748b;">No bookings found.</p>';
    }

    if (typeof BX24 !== 'undefined' && BX24 !== null) {
        try {
            BX24.resizeWindow(document.body.scrollWidth, Math.max(document.body.scrollHeight, 650));
        } catch(e) {}
    }
}

function updateStatus(bookingId, status) {
    if (!confirm('Are you sure you want to mark this booking as ' + status + '?')) return;

    var formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('booking_id', bookingId);
    formData.append('status', status);

    const postUrl = 'api.php?' + getAuthParams().substring(1);

    fetch(postUrl, {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            if (placementInfo.entityId == 0) {
                loadAllBookings();
            } else {
                loadEntityBookings();
            }
            loadSlots();
        } else {
            alert('Error updating status: ' + data.message);
        }
    })
    .catch(function(err) {
        console.error('Failed to update status:', err);
    });
}
