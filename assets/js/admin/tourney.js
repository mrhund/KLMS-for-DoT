import $ from 'jquery';

import '../modules/adminDataTable/jquery.adminDataTable.js';

const initAdminTables = () => {
    const tables = $('.admin-data-table');
    if (tables.length && typeof tables.AdminDataTable === 'function') {
        tables.AdminDataTable();
    }
};

document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', initAdminTables)
    : initAdminTables();
