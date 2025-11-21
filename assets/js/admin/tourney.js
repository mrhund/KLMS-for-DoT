import $ from 'jquery';

import '../modules/adminDataTable/jquery.adminDataTable.js';

const initAdminTables = () => {
    const tables = $('.admin-data-table');
    if (tables.length && typeof tables.AdminDataTable === 'function') {
        tables.AdminDataTable();
    }
};

const initSeedDragAndDrop = () => {
    const seedList = document.getElementById('seedList');
    if (!seedList) return;
    
    const items = seedList.querySelectorAll('.list-group-item');
    if (items.length === 0) return;
    
    let draggedItem = null;

    const updateSeeds = () => {
        seedList.querySelectorAll('.list-group-item').forEach((item, index) => {
            const badge = item.querySelector('.badge');
            if (badge) badge.textContent = index + 1;
        });
    };

    const clearBorders = () => {
        seedList.querySelectorAll('.list-group-item').forEach(i => {
            i.style.borderTop = '';
            i.style.borderBottom = '';
        });
    };

    items.forEach(item => {
        item.addEventListener('dragstart', (e) => {
            draggedItem = item;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        item.addEventListener('dragend', () => {
            item.classList.remove('dragging');
            clearBorders();
            updateSeeds();
        });

        item.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!draggedItem || draggedItem === item) return;
            
            clearBorders();
            const rect = item.getBoundingClientRect();
            const midpoint = rect.top + rect.height / 2;
            
            item.style[e.clientY > midpoint ? 'borderBottom' : 'borderTop'] = '3px solid #007bff';
        });

        item.addEventListener('dragleave', () => clearBorders());

        item.addEventListener('drop', (e) => {
            e.preventDefault();
            if (!draggedItem || draggedItem === item) return;
            
            const rect = item.getBoundingClientRect();
            const midpoint = rect.top + rect.height / 2;
            
            item.parentNode.insertBefore(
                draggedItem, 
                e.clientY > midpoint ? item.nextSibling : item
            );
            
            clearBorders();
            updateSeeds();
        });
    });
};

const init = () => {
    initAdminTables();
    initSeedDragAndDrop();
};

document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', init)
    : init();

$(document).on('shown.bs.modal', '#seedModal', () => {
    setTimeout(initSeedDragAndDrop, 50);
});
