    <script>
        // Modal handling
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        // Close modal on outside click
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                e.target.classList.add('hidden');
            }
        });

        // Search functionality
        function setupSearch(inputId, tableId) {
            const input = document.getElementById(inputId);
            const table = document.getElementById(tableId);
            if (input && table) {
                input.addEventListener('keyup', function() {
                    const filter = this.value.toLowerCase().trim();
                    const tbody = table.querySelector('tbody');
                    if (!tbody) return;
                    const rows = tbody.getElementsByTagName('tr');
                    
                    let visibleCount = 0;
                    for (let i = 0; i < rows.length; i++) {
                        const cells = rows[i].getElementsByTagName('td');
                        let found = false;
                        for (let j = 0; j < cells.length; j++) {
                            if (cells[j].textContent.toLowerCase().includes(filter)) {
                                found = true;
                                break;
                            }
                        }
                        rows[i].style.display = found ? '' : 'none';
                        if (found) visibleCount++;
                    }
                    
                    // Update result count if element exists
                    const countEl = document.getElementById('searchResultCount');
                    if (countEl) {
                        countEl.textContent = visibleCount;
                    }
                });
                
                // Add clear button functionality
                const clearBtn = document.getElementById('clearSearch');
                if (clearBtn) {
                    clearBtn.addEventListener('click', function() {
                        input.value = '';
                        input.dispatchEvent(new Event('keyup'));
                        input.focus();
                    });
                }
            }
        }

        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-auto-hide');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 3000);

        // Bulk selection functionality
        function setupBulkSelection(tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const selectAllCheckbox = table.querySelector('thead input[type="checkbox"]');
            const rowCheckboxes = table.querySelectorAll('tbody input[type="checkbox"]');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const selectedCount = document.getElementById('selectedCount');
            
            if (!selectAllCheckbox) return;
            
            // Select all functionality
            selectAllCheckbox.addEventListener('change', function() {
                rowCheckboxes.forEach(cb => {
                    const row = cb.closest('tr');
                    if (row.style.display !== 'none') {
                        cb.checked = this.checked;
                    }
                });
                updateBulkDeleteButton();
            });
            
            // Individual checkbox change
            rowCheckboxes.forEach(cb => {
                cb.addEventListener('change', function() {
                    const allChecked = Array.from(rowCheckboxes).every(c => c.checked);
                    const someChecked = Array.from(rowCheckboxes).some(c => c.checked);
                    selectAllCheckbox.checked = allChecked;
                    selectAllCheckbox.indeterminate = someChecked && !allChecked;
                    updateBulkDeleteButton();
                });
            });
            
            function updateBulkDeleteButton() {
                const checkedCount = Array.from(rowCheckboxes).filter(cb => cb.checked).length;
                if (bulkDeleteBtn) {
                    if (checkedCount > 0) {
                        bulkDeleteBtn.classList.remove('hidden');
                        if (selectedCount) selectedCount.textContent = checkedCount;
                    } else {
                        bulkDeleteBtn.classList.add('hidden');
                    }
                }
            }
        }
        
        function getSelectedIds(tableId) {
            const table = document.getElementById(tableId);
            const rowCheckboxes = table.querySelectorAll('tbody input[type="checkbox"]:checked');
            const ids = [];
            rowCheckboxes.forEach(cb => {
                const row = cb.closest('tr');
                const idCell = row.querySelector('td:nth-child(2)');
                if (idCell) ids.push(idCell.textContent.trim());
            });
            return ids;
        }
        
        function confirmBulkDelete(tableId) {
            const ids = getSelectedIds(tableId);
            if (ids.length === 0) return;
            
            document.getElementById('bulkDeleteIds').value = ids.join(',');
            document.getElementById('bulkDeleteCount').textContent = ids.length;
            openModal('bulkDeleteModal');
        }

        // Table sorting
        function sortTable(columnIndex, tableId = 'dataTable') {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const thead = table.querySelector('thead');
            
            // Determine sort direction
            const sortKey = `${tableId}-${columnIndex}`;
            const isAscending = table.dataset.sortColumn === String(columnIndex) && table.dataset.sortDir === 'asc';
            table.dataset.sortColumn = columnIndex;
            table.dataset.sortDir = isAscending ? 'desc' : 'asc';
            
            // Update sort indicators in headers
            if (thead) {
                const headers = thead.querySelectorAll('th');
                headers.forEach((th, idx) => {
                    // Remove existing sort indicators
                    const existingIcon = th.querySelector('.sort-icon');
                    if (existingIcon) existingIcon.remove();
                    
                    if (idx === columnIndex && th.classList.contains('sortable')) {
                        const icon = document.createElement('span');
                        icon.className = 'sort-icon ml-1 inline-block';
                        icon.innerHTML = isAscending ? 
                            '<svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>' :
                            '<svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path></svg>';
                        th.appendChild(icon);
                    }
                });
            }
            
            rows.sort((a, b) => {
                const aCell = a.cells[columnIndex];
                const bCell = b.cells[columnIndex];
                if (!aCell || !bCell) return 0;
                
                let aVal = aCell.textContent.trim().toLowerCase();
                let bVal = bCell.textContent.trim().toLowerCase();
                
                // Try to parse as numbers
                const aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
                const bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));
                
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return isAscending ? bNum - aNum : aNum - bNum;
                }
                
                // Try to parse as dates
                const aDate = Date.parse(aVal);
                const bDate = Date.parse(bVal);
                if (!isNaN(aDate) && !isNaN(bDate)) {
                    return isAscending ? bDate - aDate : aDate - bDate;
                }
                
                // Default string comparison
                return isAscending ? bVal.localeCompare(aVal) : aVal.localeCompare(bVal);
            });
            
            rows.forEach(row => tbody.appendChild(row));
        }
        
        // Initialize sortable table headers
        function initSortableHeaders(tableId = 'dataTable') {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const thead = table.querySelector('thead');
            if (!thead) return;
            
            const headers = thead.querySelectorAll('th.sortable');
            headers.forEach((th, idx) => {
                th.style.cursor = 'pointer';
                th.classList.add('hover:bg-gray-100', 'transition', 'select-none');
                
                // Add initial sort icon
                if (!th.querySelector('.sort-icon')) {
                    const icon = document.createElement('span');
                    icon.className = 'sort-icon ml-1 inline-block text-gray-400';
                    icon.innerHTML = '<svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path></svg>';
                    th.appendChild(icon);
                }
                
                // Get actual column index (accounting for all ths)
                const allHeaders = thead.querySelectorAll('th');
                let actualIndex = Array.from(allHeaders).indexOf(th);
                
                th.addEventListener('click', function() {
                    sortTable(actualIndex, tableId);
                });
            });
        }

        // Initialize bulk selection on page load
        document.addEventListener('DOMContentLoaded', function() {
            setupBulkSelection('dataTable');
            setupSearch('searchInput', 'dataTable');
            initSortableHeaders('dataTable');
            
            // Mobile sidebar toggle
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            const closeSidebar = document.getElementById('closeSidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            
            function openSidebar() {
                if (sidebar) {
                    sidebar.classList.add('open');
                }
                if (sidebarOverlay) {
                    sidebarOverlay.classList.add('open');
                }
                document.body.style.overflow = 'hidden';
            }
            
            function closeSidebarFn() {
                if (sidebar) {
                    sidebar.classList.remove('open');
                }
                if (sidebarOverlay) {
                    sidebarOverlay.classList.remove('open');
                }
                document.body.style.overflow = '';
            }
            
            if (menuToggle) {
                menuToggle.addEventListener('click', openSidebar);
            }
            
            if (closeSidebar) {
                closeSidebar.addEventListener('click', closeSidebarFn);
            }
            
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', closeSidebarFn);
            }
            
            // Close sidebar when clicking a link on mobile
            if (sidebar) {
                const sidebarLinks = sidebar.querySelectorAll('a');
                sidebarLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        if (window.innerWidth < 1024) {
                            closeSidebarFn();
                        }
                    });
                });
            }
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 1024) {
                    closeSidebarFn();
                }
            });
        });
    </script>
</body>
</html>
