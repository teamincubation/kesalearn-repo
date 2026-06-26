/**
 * KESA Learn - Admin Bulk Actions
 * Handles select all, bulk delete functionality for data tables
 */

document.addEventListener('DOMContentLoaded', function() {
    initBulkActions();
});

function initBulkActions() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCountEl = document.getElementById('selectedCount');
    
    if (!selectAllCheckbox) return;
    
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    
    // Select All functionality
    selectAllCheckbox.addEventListener('change', function() {
        rowCheckboxes.forEach(cb => {
            cb.checked = this.checked;
        });
        updateBulkActionsBar();
    });
    
    // Individual checkbox change
    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            updateSelectAllState();
            updateBulkActionsBar();
        });
    });
    
    // Update select all checkbox state
    function updateSelectAllState() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        const totalCount = rowCheckboxes.length;
        
        selectAllCheckbox.checked = checkedCount === totalCount && totalCount > 0;
        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < totalCount;
    }
    
    // Update bulk actions bar visibility
    function updateBulkActionsBar() {
        const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
        const count = checkedBoxes.length;
        
        if (bulkActionsBar) {
            if (count > 0) {
                bulkActionsBar.classList.add('visible');
                if (selectedCountEl) {
                    selectedCountEl.textContent = count;
                }
            } else {
                bulkActionsBar.classList.remove('visible');
            }
        }
    }
    
    // Bulk delete confirmation
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
            const count = checkedBoxes.length;
            
            if (count === 0) {
                alert('Please select at least one item to delete.');
                return;
            }
            
            const itemName = this.dataset.itemName || 'items';
            if (!confirm(`Are you sure you want to delete ${count} ${itemName}? This action cannot be undone.`)) {
                return;
            }
            
            // Collect IDs
            const ids = Array.from(checkedBoxes).map(cb => cb.value);
            
            // Submit via form
            const form = document.getElementById('bulkDeleteForm');
            if (form) {
                document.getElementById('bulkDeleteIds').value = ids.join(',');
                form.submit();
            }
        });
    }
}

// Expose for dynamic tables
window.initBulkActions = initBulkActions;
