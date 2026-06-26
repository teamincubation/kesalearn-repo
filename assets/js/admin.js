/**
 * KESA Learn - Admin Panel JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {

    /* =====================================================
       Sidebar Toggle (Mobile)
       ===================================================== */
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.admin-sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('active');
        });
        
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            });
        }
    }

    /* =====================================================
       Dynamic Form Field Builder
       ===================================================== */
    const fieldBuilder = document.getElementById('field-builder');
    if (fieldBuilder) {
        const addFieldBtn = document.getElementById('add-field-btn');
        let fieldCount = fieldBuilder.querySelectorAll('.field-row').length;
        
        if (addFieldBtn) {
            addFieldBtn.addEventListener('click', function() {
                fieldCount++;
                const row = document.createElement('div');
                row.className = 'field-row';
                row.style.display = 'flex';
                row.style.gap = '12px';
                row.style.marginBottom = '12px';
                row.style.alignItems = 'flex-end';
                
                row.innerHTML = `
                    <div class="form-group" style="flex:2">
                        <label>Field Label</label>
                        <input type="text" name="fields[${fieldCount}][label]" class="form-control" placeholder="e.g. College Name" required>
                    </div>
                    <div class="form-group" style="flex:1">
                        <label>Type</label>
                        <select name="fields[${fieldCount}][type]" class="form-control">
                            <option value="text">Text</option>
                            <option value="email">Email</option>
                            <option value="tel">Phone</option>
                            <option value="textarea">Textarea</option>
                            <option value="select">Dropdown</option>
                            <option value="number">Number</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:0.5">
                        <label class="form-check">
                            <input type="checkbox" name="fields[${fieldCount}][required]" value="1" checked>
                            Required
                        </label>
                    </div>
                    <button type="button" class="btn btn-sm" style="background:var(--red-light);color:var(--red);margin-bottom:20px;" onclick="this.parentElement.remove()">Remove</button>
                `;
                
                fieldBuilder.appendChild(row);
            });
        }
    }

    /* =====================================================
       Select All Checkbox (Table)
       ===================================================== */
    const selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                cb.checked = this.checked;
            });
        });
    }

    /* =====================================================
       Admin Search with Debounce
       ===================================================== */
    const searchInput = document.querySelector('.admin-search');
    if (searchInput) {
        let timeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                const query = this.value.toLowerCase();
                document.querySelectorAll('.table tbody tr').forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(query) ? '' : 'none';
                });
            }, 300);
        });
    }

    /* =====================================================
       Chart.js Initialization (if canvas exists)
       ===================================================== */
    const registrationsChart = document.getElementById('registrations-chart');
    if (registrationsChart && typeof Chart !== 'undefined') {
        const ctx = registrationsChart.getContext('2d');
        
        // Data will be populated via PHP JSON
        const chartData = window.chartData || {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            registrations: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            revenue: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]
        };
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Registrations',
                    data: chartData.registrations,
                    backgroundColor: 'rgba(73, 80, 186, 0.8)',
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f3f5' },
                        ticks: { font: { size: 12 }, color: '#6c757d' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 12 }, color: '#6c757d' }
                    }
                }
            }
        });
    }

    const revenueChart = document.getElementById('revenue-chart');
    if (revenueChart && typeof Chart !== 'undefined') {
        const ctx = revenueChart.getContext('2d');
        const chartData = window.revenueChartData || {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
            values: [0, 0, 0, 0, 0, 0]
        };
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Revenue (INR)',
                    data: chartData.values,
                    borderColor: '#e7404a',
                    backgroundColor: 'rgba(231, 64, 74, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#e7404a',
                    pointBorderWidth: 0,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f3f5' },
                        ticks: { font: { size: 12 }, color: '#6c757d' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 12 }, color: '#6c757d' }
                    }
                }
            }
        });
    }

    const eventTypesChart = document.getElementById('event-types-chart');
    if (eventTypesChart && typeof Chart !== 'undefined') {
        const ctx = eventTypesChart.getContext('2d');
        const chartData = window.eventTypesData || {
            labels: ['Webinars', 'Workshops', 'Offline', 'Special'],
            values: [0, 0, 0, 0]
        };
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: chartData.labels,
                datasets: [{
                    data: chartData.values,
                    backgroundColor: ['#4950ba', '#a058ae', '#e7404a', '#f5cb39'],
                    borderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 16, font: { size: 12 }, color: '#6c757d' }
                    }
                },
                cutout: '65%'
            }
        });
    }

    /* =====================================================
       Image Preview for Upload
       ===================================================== */
    document.querySelectorAll('.image-upload-input').forEach(input => {
        input.addEventListener('change', function() {
            const preview = document.getElementById(this.dataset.preview);
            if (preview && this.files[0]) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    });

    /* =====================================================
       Bulk Actions
       ===================================================== */
    const bulkActionBtn = document.getElementById('bulk-action-btn');
    if (bulkActionBtn) {
        bulkActionBtn.addEventListener('click', function() {
            const action = document.getElementById('bulk-action').value;
            const checked = document.querySelectorAll('.row-checkbox:checked');
            
            if (!action) {
                alert('Please select an action.');
                return;
            }
            
            if (checked.length === 0) {
                alert('Please select at least one item.');
                return;
            }
            
            if (confirm('Apply "' + action + '" to ' + checked.length + ' selected items?')) {
                const ids = Array.from(checked).map(cb => cb.value);
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="bulk_action" value="${action}">
                    <input type="hidden" name="ids" value="${ids.join(',')}">
                    <input type="hidden" name="csrf_token" value="${document.querySelector('meta[name=csrf-token]')?.content || ''}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

});
