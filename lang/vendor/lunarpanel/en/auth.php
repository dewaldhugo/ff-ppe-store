<?php

// The store's own role and permission in the admin's Staff screens (Lunar's lunarpanel::auth file has the rest; a key here
// is added to those). App\Admin\ReportsAccess.
return [
    'roles.reports.label' => 'Reports',
    'roles.reports.description' => 'Sees the dashboard, Invoices and Financial reporting only',
    'permissions.reports:view.label' => 'View reports',
    'permissions.reports:view.description' => 'Allow the staff member to open Invoices and Financial reporting (the dashboard is open to every staff member)',
];
