/**
 * Custom Bitrix24 Booking Widget Frontend Engine
 */
let placementInfo = {
    entityType: 'NONE',
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
                        placementInfo.entityId = parseInt(placement.options.ID || placement.options.id || '0', 10);
                    } else if (placement.placement === 'CRM_LEAD_DETAIL_TAB') {
                        placementInfo.entityType = 'LEAD';
                        placementInfo.entityId = parseInt(placement.options.ID || placement.options.id || '0', 10);
                    } else {
                        placementInfo.entityType = 'NONE';
                        placementInfo.entityId = 0;
                    }
                } else {
                    placementInfo.entityType = 'NONE';
                    placementInfo.entityId = 0;
                }

                BX24.resizeWindow(document.body.scrollWidth, Math.max(document.body.scrollHeight, 650));

                if (placementInfo.entityId === 0) {
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
    placementInfo.entityType = urlParams.get('entity_type') || 'NONE';
    placementInfo.entityId = parseInt(urlParams.get('entity_id') || '0', 10);

    if (placementInfo.entityId === 0) {
        adjustLayoutForStandalone();
    } else {
        loadEntityBookings();
    }
}

function adjustLayoutForStandalone() {
    // Keep Left Form Column Visible (was previously hidden)
    const formCard = document.getElementById('booking_form').closest('.card');
    if (formCard) {
        formCard.style.display = 'block';
    }

    // Adjust Grid Layout for Standalone Mode
    const grid = document.querySelector('.booking-grid');
    if (grid) {
        grid.style.gridTemplateColumns = '380px 1fr';
    }

    // Change title of bookings card
    var titleEl = document.getElementById('bookings_card_title');
    if (titleEl) {
        titleEl.textContent = 'All Scheduled Appointments';
    }

    // Show view toggle
    var viewToggle = document.getElementById('view_toggle');
    if (viewToggle) {
        viewToggle.style.display = 'inline-flex';
    }

    // Default to calendar view in standalone mode
    switchView('calendar');
}

// ===== Calendar View State =====
var calCurrentDate = new Date();
var calResources = [];
var calBookings = [];
var calCurrentView = 'calendar'; // 'calendar' or 'list'
var calMiniMonthDate = new Date();

var RESOURCE_COLORS = {
    'driver': { bg: '#eff6ff', text: '#2563eb', border: '#3b82f6', icon: '🚗' },
    'meeting room': { bg: '#ecfdf5', text: '#059669', border: '#10b981', icon: '🏢' },
    'photo grapher': { bg: '#fdf4ff', text: '#a855f7', border: '#a855f7', icon: '📷' },
    'photographer': { bg: '#fdf4ff', text: '#a855f7', border: '#a855f7', icon: '📷' },
    'video grapher': { bg: '#fff7ed', text: '#ea580c', border: '#f97316', icon: '🎥' },
    'videographer': { bg: '#fff7ed', text: '#ea580c', border: '#f97316', icon: '🎥' }
};

var SPA_STAGES = {
    'DT1088_37:NEW': { name: 'Request Made', color: '#22b9ff' },
    'DT1088_37:PREPARATION': { name: 'Sales Admin Approval', color: '#88b9ff' },
    'DT1088_37:CLIENT': { name: 'Executive Approval', color: '#10e5fc' },
    'DT1088_37:SUCCESS': { name: 'Reserved', color: '#00ff00' },
    'DT1088_37:FAIL': { name: 'Canceled', color: '#ff0000' }
};

function switchView(viewName) {
    calCurrentView = viewName;
    var calView = document.getElementById('calendar_view');
    var listView = document.getElementById('list_view');
    var btnCal = document.getElementById('btn_calendar_view');
    var btnList = document.getElementById('btn_list_view');

    if (viewName === 'calendar') {
        calView.style.display = 'block';
        listView.style.display = 'none';
        btnCal.classList.add('active');
        btnList.classList.remove('active');
        loadCalendarBookings();
    } else {
        calView.style.display = 'none';
        listView.style.display = 'block';
        btnCal.classList.remove('active');
        btnList.classList.add('active');
        loadAllBookings();
    }
}

function calNavigate(delta) {
    calCurrentDate.setDate(calCurrentDate.getDate() + delta);
    loadCalendarBookings();
}

function calGoToday() {
    calCurrentDate = new Date();
    calMiniMonthDate = new Date();
    loadCalendarBookings();
}

function loadCalendarBookings() {
    var dateStr = formatDate(calCurrentDate);

    // Show loading spinner
    var wrapper = document.getElementById('calendar_grid_wrapper');
    wrapper.innerHTML = '<div class="cal-loading"><div class="spinner"></div>Loading calendar…</div>';

    // Update nav title
    var titleEl = document.getElementById('cal_nav_title');
    var dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    titleEl.textContent = dayNames[calCurrentDate.getDay()] + ', ' + monthNames[calCurrentDate.getMonth()] + ' ' + calCurrentDate.getDate() + ', ' + calCurrentDate.getFullYear();

    fetch('api.php?action=get_calendar_bookings&start_date=' + dateStr + '&end_date=' + dateStr + getAuthParams())
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                calResources = data.resources || [];
                calBookings = data.bookings || [];
                renderCalendarGrid();
                renderMiniMonth();
            } else {
                wrapper.innerHTML = '<p style="text-align:center; color:#ef4444; padding:20px;">Failed to load calendar data.</p>';
            }
        })
        .catch(function(err) {
            console.error('Calendar load error:', err);
            wrapper.innerHTML = '<p style="text-align:center; color:#ef4444; padding:20px;">Error loading calendar.</p>';
        });
}

function formatDate(d) {
    var y = d.getFullYear();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
}

function formatTime12(timeStr) {
    var parts = timeStr.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1] || '00';
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    return h + ':' + m + ' ' + ampm;
}

function renderCalendarGrid() {
    var wrapper = document.getElementById('calendar_grid_wrapper');

    // Use resources as columns; fallback to hardcoded if none returned
    var columns = [];
    if (calResources.length > 0) {
        calResources.forEach(function(r) {
            var rName = r.name || r.NAME || 'Resource';
            var rId = r.id || r.ID || 0;
            columns.push({ id: rId, name: rName });
        });
    } else {
        columns = [
            { id: 'driver', name: 'Driver' },
            { id: 'meeting_room', name: 'Meeting Room' },
            { id: 'photographer', name: 'Photo Grapher' },
            { id: 'videographer', name: 'Video Grapher' }
        ];
    }

    // Generate 30-min time slots from 07:00 to 19:00
    var timeSlots = [];
    for (var h = 7; h < 19; h++) {
        timeSlots.push({ hour: h, minute: 0, label: formatTime12(String(h).padStart(2, '0') + ':00') });
        timeSlots.push({ hour: h, minute: 30, label: '' });
    }

    var numCols = columns.length + 1; // +1 for time column

    var html = '<div class="calendar-grid" style="grid-template-columns: 70px repeat(' + columns.length + ', 1fr);">';

    // Header row
    html += '<div class="cg-header">';
    html += '<div class="cg-header-cell cg-time-header">Time</div>';
    columns.forEach(function(col) {
        var rKey = col.name.toLowerCase();
        var colors = RESOURCE_COLORS[rKey] || { bg: '#f1f5f9', text: '#475569', border: '#94a3b8', icon: '📌' };
        html += '<div class="cg-header-cell">' +
            '<div class="resource-icon" style="background:' + colors.bg + ';">' + colors.icon + '</div>' +
            col.name +
            '</div>';
    });
    html += '</div>';

    // Time rows
    timeSlots.forEach(function(slot, rowIndex) {
        html += '<div class="cg-row">';
        html += '<div class="cg-time-label">' + (slot.label || '') + '</div>';

        columns.forEach(function(col, colIndex) {
            var cellId = 'cell_' + rowIndex + '_' + colIndex;
            html += '<div class="cg-cell" id="' + cellId + '" data-row="' + rowIndex + '" data-col="' + colIndex + '" data-resource-id="' + col.id + '" data-resource-name="' + col.name + '"></div>';
        });

        html += '</div>';
    });

    html += '</div>';
    wrapper.innerHTML = html;

    // Place event cards
    placeEventCards(columns, timeSlots);
}

function placeEventCards(columns, timeSlots) {
    // Map column index to list of booking events placed in it
    var colEventsMap = {};
    columns.forEach(function(col, colIndex) {
        colEventsMap[colIndex] = [];
    });

    calBookings.forEach(function(booking) {
        // Determine which resource column(s) this booking belongs to
        var bookingResourceIds = [];
        if (booking.ufCrm29_1787324656) {
            bookingResourceIds = String(booking.ufCrm29_1787324656).split(',').map(function(s) { return s.trim(); });
        }

        // Match booking to columns
        var matchedColumns = [];
        columns.forEach(function(col, colIndex) {
            var colId = String(col.id);
            var colName = col.name.toLowerCase();

            if (bookingResourceIds.indexOf(colId) >= 0) {
                matchedColumns.push(colIndex);
                return;
            }

            var spaMap = { '699': ['driver'], '701': ['meeting room'], '703': ['photo grapher', 'photographer'], '705': ['video grapher', 'videographer'] };
            bookingResourceIds.forEach(function(resId) {
                if (spaMap[resId]) {
                    spaMap[resId].forEach(function(name) {
                        if (colName.indexOf(name) >= 0 || name.indexOf(colName) >= 0) {
                            if (matchedColumns.indexOf(colIndex) < 0) {
                                matchedColumns.push(colIndex);
                            }
                        }
                    });
                }
            });
        });

        if (matchedColumns.length === 0) {
            matchedColumns.push(0);
        }

        // Calculate time details
        var startParts = booking.start_time.split(':');
        var endParts = booking.end_time.split(':');
        var startMinutes = parseInt(startParts[0], 10) * 60 + parseInt(startParts[1], 10);
        var endMinutes = parseInt(endParts[0], 10) * 60 + parseInt(endParts[1], 10);

        var gridStartMinutes = 7 * 60; // 07:00
        var slotHeight = 48; // px per 30-min slot

        var topPx = ((startMinutes - gridStartMinutes) / 30) * slotHeight;
        var heightPx = ((endMinutes - startMinutes) / 30) * slotHeight;
        if (heightPx < 24) heightPx = 24;

        matchedColumns.forEach(function(colIndex) {
            colEventsMap[colIndex].push({
                booking: booking,
                start_minutes: startMinutes,
                end_minutes: endMinutes,
                topPx: topPx,
                heightPx: heightPx
            });
        });
    });

    // Render columns side-by-side if there are overlaps
    columns.forEach(function(col, colIndex) {
        var events = colEventsMap[colIndex] || [];
        if (events.length === 0) return;

        // Sort events by start time
        events.sort(function(a, b) { return a.start_minutes - b.start_minutes; });

        // Group into overlapping clusters
        var clusters = [];
        events.forEach(function(ev) {
            var placed = false;
            for (var i = 0; i < clusters.length; i++) {
                var cluster = clusters[i];
                var overlaps = cluster.some(function(item) {
                    return ev.start_minutes < item.end_minutes && ev.end_minutes > item.start_minutes;
                });
                if (overlaps) {
                    cluster.push(ev);
                    placed = true;
                    break;
                }
            }
            if (!placed) {
                clusters.push([ev]);
            }
        });

        // For each cluster, calculate columns layout (sub-columns)
        clusters.forEach(function(cluster) {
            var subCols = []; // Stores end minutes of events in each sub-column
            cluster.forEach(function(ev) {
                var assignedIndex = -1;
                for (var i = 0; i < subCols.length; i++) {
                    if (ev.start_minutes >= subCols[i]) {
                        assignedIndex = i;
                        subCols[i] = ev.end_minutes;
                        break;
                    }
                }
                if (assignedIndex === -1) {
                    subCols.push(ev.end_minutes);
                    assignedIndex = subCols.length - 1;
                }
                ev.subColIndex = assignedIndex;
            });

            cluster.forEach(function(ev) {
                ev.totalSubCols = subCols.length;
            });
        });

        // Add to DOM
        var cellId = 'cell_0_' + colIndex;
        var cell = document.getElementById(cellId);
        if (!cell) return;
        cell.style.position = 'relative';

        events.forEach(function(ev) {
            var booking = ev.booking;
            var serviceColor = booking.service_color || '#3b82f6';
            var statusInfo = SPA_STAGES[booking.status] || { name: booking.status, color: '#64748b' };

            var eventEl = document.createElement('div');
            eventEl.className = 'cal-event';
            
            // Layout calculations
            var widthPercent = 100 / ev.totalSubCols;
            var leftPercent = widthPercent * ev.subColIndex;

            eventEl.style.top = (ev.topPx + 1) + 'px';
            eventEl.style.height = Math.max(22, ev.heightPx - 2) + 'px';
            eventEl.style.width = 'calc(' + widthPercent + '% - 4px)';
            eventEl.style.left = 'calc(' + leftPercent + '% + 2px)';
            eventEl.style.boxSizing = 'border-box';
            eventEl.style.background = '#ffffff';
            eventEl.style.borderLeftColor = serviceColor;
            eventEl.style.color = '#1e293b';

            var clientDisplay = booking.client_name || 'N/A';
            var createdBy = booking.created_by_name || '';

            eventEl.innerHTML =
                '<div class="cal-event-title" style="font-weight: 700; color:' + serviceColor + ';">' + escapeHtml(booking.service_name || 'Booking') + '</div>' +
                '<div class="cal-event-time" style="font-weight: 500;">' + formatTime12(booking.start_time) + ' – ' + formatTime12(booking.end_time) + '</div>' +
                '<div class="cal-event-client">👤 ' + escapeHtml(clientDisplay) + '</div>' +
                (createdBy ? '<div class="cal-event-staff">By: ' + escapeHtml(createdBy) + '</div>' : '') +
                '<div style="margin-top:2px; display:flex; align-items:center; gap:3px;"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:' + statusInfo.color + ';"></span><span style="font-size:8px;opacity:0.8;font-weight:600;">' + escapeHtml(statusInfo.name) + '</span></div>';

            eventEl.onclick = function(e) {
                e.stopPropagation();
                showEventPopup(booking);
            };

            cell.appendChild(eventEl);
        });
    });
}

function hexToRgba(hex, alpha) {
    hex = hex.replace('#', '');
    if (hex.length === 3) {
        hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
    }
    var r = parseInt(hex.substring(0, 2), 16);
    var g = parseInt(hex.substring(2, 4), 16);
    var b = parseInt(hex.substring(4, 6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function showEventPopup(booking) {
    // Remove any existing popup
    var existing = document.querySelector('.cal-event-popup-overlay');
    if (existing) existing.remove();

    var statusInfo = SPA_STAGES[booking.status] || { name: booking.status, color: '#64748b' };
    var serviceColor = booking.service_color || '#3b82f6';

    // CRM link
    var crmLinkHtml = '';
    if (booking.entity_id > 0) {
        var domain = 'capitalwestern.bitrix24.com';
        if (typeof BX24 !== 'undefined' && BX24 !== null) {
            try { var auth = BX24.getAuth(); if (auth && auth.domain) domain = auth.domain; } catch(e) {}
        }
        var entityPath = (booking.entity_type || '').toLowerCase() === 'lead' ? 'lead' : 'deal';
        var entityUrl = 'https://' + domain + '/crm/' + entityPath + '/details/' + booking.entity_id + '/';
        var displayTitle = booking.entity_title || (booking.entity_type + ' #' + booking.entity_id);
        crmLinkHtml = '<a href="' + entityUrl + '" target="_blank" style="color:var(--primary); text-decoration:none; font-weight:500;">' + escapeHtml(displayTitle) + ' ↗</a>';
    }

    // Status change buttons
    var buttonsHtml = '';
    Object.keys(SPA_STAGES).forEach(function(stageId) {
        if (booking.status !== stageId) {
            var stage = SPA_STAGES[stageId];
            buttonsHtml += '<button class="popup-status-btn" style="border-color:' + stage.color + '; color:' + stage.color + ';" onclick="updateStatusFromPopup(' + booking.id + ', \'' + stageId + '\')">' + stage.name + '</button>';
        }
    });

    var overlay = document.createElement('div');
    overlay.className = 'cal-event-popup-overlay';
    overlay.onclick = function(e) {
        if (e.target === overlay) overlay.remove();
    };

    overlay.innerHTML =
        '<div class="cal-event-popup">' +
            '<div class="popup-header" style="background: linear-gradient(135deg, ' + serviceColor + ', ' + serviceColor + 'cc);">' +
                '<button class="popup-close" onclick="this.closest(\'.cal-event-popup-overlay\').remove()">✕</button>' +
                '<h4>' + escapeHtml(booking.service_name || 'Booking') + '</h4>' +
                '<div class="popup-time">' + booking.booking_date + ' • ' + formatTime12(booking.start_time) + ' – ' + formatTime12(booking.end_time) + '</div>' +
            '</div>' +
            '<div class="popup-body">' +
                '<div class="popup-row"><span class="popup-label">Status</span><span class="popup-value"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + statusInfo.color + ';margin-right:5px;"></span>' + escapeHtml(statusInfo.name) + '</span></div>' +
                '<div class="popup-row"><span class="popup-label">Client</span><span class="popup-value">' + escapeHtml(booking.client_name || 'N/A') + '</span></div>' +
                '<div class="popup-row"><span class="popup-label">Phone</span><span class="popup-value">' + escapeHtml(booking.client_phone || 'N/A') + '</span></div>' +
                '<div class="popup-row"><span class="popup-label">Staff</span><span class="popup-value">' + escapeHtml(booking.staff_name || 'N/A') + '</span></div>' +
                (booking.created_by_name ? '<div class="popup-row"><span class="popup-label">Created By</span><span class="popup-value">' + escapeHtml(booking.created_by_name) + '</span></div>' : '') +
                (crmLinkHtml ? '<div class="popup-row"><span class="popup-label">CRM</span><span class="popup-value">' + crmLinkHtml + '</span></div>' : '') +
            '</div>' +
            (buttonsHtml ? '<div class="popup-footer">' + buttonsHtml + '</div>' : '') +
        '</div>';

    document.body.appendChild(overlay);
}

function updateStatusFromPopup(bookingId, status) {
    if (!confirm('Change status to ' + (SPA_STAGES[status] ? SPA_STAGES[status].name : status) + '?')) return;

    // Close popup
    var overlay = document.querySelector('.cal-event-popup-overlay');
    if (overlay) overlay.remove();

    var formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('booking_id', bookingId);
    formData.append('status', status);

    var postUrl = 'api.php?' + getAuthParams().substring(1);

    fetch(postUrl, { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                if (calCurrentView === 'calendar') {
                    loadCalendarBookings();
                } else {
                    loadAllBookings();
                }
            } else {
                alert('Error updating status: ' + data.message);
            }
        })
        .catch(function(err) { console.error('Failed to update status:', err); });
}

// ===== Mini Month Calendar =====
function renderMiniMonth() {
    var container = document.getElementById('mini_month_calendar');
    var year = calMiniMonthDate.getFullYear();
    var month = calMiniMonthDate.getMonth();
    var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    var today = new Date();
    var selectedDateStr = formatDate(calCurrentDate);

    // Determine which dates have events
    var eventDates = {};
    calBookings.forEach(function(b) {
        eventDates[b.booking_date] = true;
    });

    var firstDay = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var daysInPrevMonth = new Date(year, month, 0).getDate();

    var html = '<div class="mini-month-header">' +
        '<button type="button" class="mini-month-nav" onclick="miniMonthNav(-1)">◀</button>' +
        '<span class="mini-month-title">' + monthNames[month] + ' ' + year + '</span>' +
        '<button type="button" class="mini-month-nav" onclick="miniMonthNav(1)">▶</button>' +
        '</div>';

    html += '<div class="mini-month-grid">';

    // Day headers
    var dayLabels = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    dayLabels.forEach(function(label) {
        html += '<div class="day-header">' + label + '</div>';
    });

    // Previous month trailing days
    for (var i = firstDay - 1; i >= 0; i--) {
        var prevDay = daysInPrevMonth - i;
        html += '<div class="day-cell other-month">' + prevDay + '</div>';
    }

    // Current month days
    for (var d = 1; d <= daysInMonth; d++) {
        var dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
        var classes = 'day-cell';
        if (dateStr === formatDate(today)) classes += ' today';
        if (dateStr === selectedDateStr) classes += ' selected';
        if (eventDates[dateStr]) classes += ' has-events';
        html += '<div class="' + classes + '" onclick="miniMonthSelectDay(' + year + ',' + month + ',' + d + ')">' + d + '</div>';
    }

    // Next month leading days
    var totalCells = firstDay + daysInMonth;
    var remaining = (7 - (totalCells % 7)) % 7;
    for (var j = 1; j <= remaining; j++) {
        html += '<div class="day-cell other-month">' + j + '</div>';
    }

    html += '</div>';
    container.innerHTML = html;
}

function miniMonthNav(delta) {
    calMiniMonthDate.setMonth(calMiniMonthDate.getMonth() + delta);
    renderMiniMonth();
}

function miniMonthSelectDay(year, month, day) {
    calCurrentDate = new Date(year, month, day);
    calMiniMonthDate = new Date(year, month, 1);
    loadCalendarBookings();
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
                handleCarReservationRules();
            }
        })
        .catch(function(err) {
            console.error('Failed to load services/staff:', err);
        });
}

function handleCarReservationRules() {
    var resourceSelect = document.getElementById('b24_resource_id');
    var carSelect = document.getElementById('ufCrm29_1787324769682');
    if (!resourceSelect || !carSelect) return;

    var isDriverSelected = false;
    for (var i = 0; i < resourceSelect.options.length; i++) {
        var opt = resourceSelect.options[i];
        if (opt.selected && opt.text.toLowerCase().indexOf('driver') >= 0) {
            isDriverSelected = true;
            break;
        }
    }

    var noCarOpt = carSelect.querySelector('option[value="707"]');
    var car1Opt = carSelect.querySelector('option[value="709"]');
    var car2Opt = carSelect.querySelector('option[value="711"]');
    var car3Opt = carSelect.querySelector('option[value="713"]');

    if (isDriverSelected) {
        // Forced to choose Car 1, Car 2, or Car 3
        if (noCarOpt) noCarOpt.disabled = true;
        if (car1Opt) car1Opt.disabled = false;
        if (car2Opt) car2Opt.disabled = false;
        if (car3Opt) car3Opt.disabled = false;

        if (carSelect.value === '707' || !carSelect.value) {
            carSelect.value = '709'; // Default to Car 1
        }
    } else {
        // Forced to choose No Car
        if (noCarOpt) noCarOpt.disabled = false;
        if (car1Opt) car1Opt.disabled = true;
        if (car2Opt) car2Opt.disabled = true;
        if (car3Opt) car3Opt.disabled = true;

        carSelect.value = '707'; // Default to No Car
    }
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

    var b24ResSelect = document.getElementById('b24_resource_id');
    if (b24ResSelect) {
        b24ResSelect.addEventListener('change', handleCarReservationRules);
    }

    document.getElementById('booking_form').addEventListener('submit', function(e) {
        e.preventDefault();
        if (!selectedSlot) {
            alert('Please select an available time slot.');
            return;
        }

        // Validate Car Selection rules
        var carVal = document.getElementById('ufCrm29_1787324769682').value;
        var resSelect = document.getElementById('b24_resource_id');
        var isDriverSelected = false;
        for (var i = 0; i < resSelect.options.length; i++) {
            if (resSelect.options[i].selected && resSelect.options[i].text.toLowerCase().indexOf('driver') >= 0) {
                isDriverSelected = true;
                break;
            }
        }

        if (isDriverSelected && carVal === '707') {
            alert('When "Driver" is selected, you must choose Car 1, Car 2, or Car 3.');
            return;
        }
        if (!isDriverSelected && carVal !== '707') {
            alert('Car reservation (Car 1/2/3) is only permitted when "Driver" is selected.');
            return;
        }

        var entityType = (placementInfo && placementInfo.entityId > 0) ? placementInfo.entityType : 'NONE';
        var entityId = (placementInfo && placementInfo.entityId > 0) ? placementInfo.entityId : 0;

        var formData = new FormData(this);
        formData.append('action', 'create_booking');
        formData.append('entity_type', entityType);
        formData.append('entity_id', entityId);
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
                if (entityType === 'NONE' || entityId === 0) {
                    loadAllBookings();
                } else {
                    loadEntityBookings();
                }
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

            var spaStages = {
                'DT1088_37:NEW': { name: 'Request Made', color: '#22b9ff' },
                'DT1088_37:PREPARATION': { name: 'Sales Admin Approval', color: '#88b9ff' },
                'DT1088_37:CLIENT': { name: 'Executive Approval', color: '#10e5fc' },
                'DT1088_37:SUCCESS': { name: 'Reserved', color: '#00ff00' },
                'DT1088_37:FAIL': { name: 'Canceled', color: '#ff0000' }
            };

            var currentStatusKey = b.status;
            if (currentStatusKey === 'Scheduled') currentStatusKey = 'DT1088_37:NEW';
            if (currentStatusKey === 'Confirmed') currentStatusKey = 'DT1088_37:PREPARATION';
            if (currentStatusKey === 'Completed') currentStatusKey = 'DT1088_37:SUCCESS';
            if (currentStatusKey === 'Cancelled') currentStatusKey = 'DT1088_37:FAIL';

            var currentStage = spaStages[currentStatusKey] || { name: b.status, color: '#64748b' };
            var statusBadge = '<span class="status-pill" style="background-color: ' + currentStage.color + '20; color: ' + currentStage.color + '; border: 1px solid ' + currentStage.color + '40; font-size:11px; padding:3px 8px; border-radius:4px; font-weight:bold;">' + currentStage.name + '</span>';

            var buttonsHtml = '';
            Object.keys(spaStages).forEach(function(stageId) {
                if (currentStatusKey !== stageId) {
                    var stage = spaStages[stageId];
                    buttonsHtml += '<button class="btn btn-outline btn-sm" style="border-color: ' + stage.color + '; color: ' + stage.color + '; margin-right: 6px; margin-bottom: 6px; background: transparent; font-weight: 600; cursor: pointer; transition: all 0.2s;" onclick="updateStatus(' + b.id + ', \'' + stageId + '\')">' + stage.name + '</button>';
                }
            });

            item.innerHTML =
                '<div class="booking-item-header">' +
                    '<div>' +
                        '<span class="service-badge" style="background-color: ' + (b.service_color || '#2563eb') + '">' + b.service_name + '</span>' +
                        crmBadge +
                    '</div>' +
                    statusBadge +
                '</div>' +
                '<div class="booking-details">' +
                    '<strong>Date:</strong> ' + b.booking_date + ' (' + b.start_time + ' - ' + b.end_time + ')<br>' +
                    '<strong>Staff:</strong> ' + b.staff_name + '<br>' +
                    '<strong>Client:</strong> ' + (b.client_name || 'N/A') + ' (' + (b.client_phone || 'N/A') + ')<br>' +
                    '<strong>Created By:</strong> ' + (b.created_by_name || 'N/A') + '<br>' +
                    '<strong>Target Calendar:</strong> ' + (b.calendar_target === 'user' ? 'My Calendar' : (b.calendar_target === 'company_calendar' ? 'Public (Company Calendar)' : b.calendar_target)) +
                '</div>' +
                '<div class="booking-actions" style="margin-top: 10px; display: flex; flex-wrap: wrap;">' +
                    buttonsHtml +
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
