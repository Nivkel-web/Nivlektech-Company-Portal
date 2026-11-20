<?php
/*
|--------------------------------------------------------------------------
| Nivlektech Company Portal
|--------------------------------------------------------------------------
| File : sidebar.php
|--------------------------------------------------------------------------
*/
?>

<div class="container-fluid">

    <div class="row">

        <!-- Sidebar -->

        <div class="col-md-2 bg-dark text-white vh-100 p-0">

            <div class="p-3 border-bottom">

                <h4 class="text-center">

                    <i class="bi bi-building"></i>

                    Portal

                </h4>

            </div>

            <div class="list-group list-group-flush">

                <a href="dashboard.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-speedometer2"></i>

                    Dashboard

                </a>

                <a href="users.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-people"></i>

                    Users

                </a>

                <a href="departments.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-building"></i>

                    Departments

                </a>

                <a href="employees.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-person-badge"></i>

                    Employees

                </a>

                <a href="tickets.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-ticket-perforated"></i>

                    Ticketing System

                </a>

<?php if (strtolower($_SESSION['role'] ?? '') === 'admin') { ?>

                <a href="live-management.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-broadcast"></i>

                    Live Management

                </a>

                <a href="ticket-reports.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-graph-up"></i>

                    Report Analytics

                </a>

                <a href="email-settings.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-envelope-at"></i>

                    Email Notification

                </a>

                <a href="auto-assign.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-shuffle"></i>

                    Auto-Assign

                </a>

                <a href="sms-settings.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-chat-dots"></i>

                    SMS Alerts

                </a>

<?php } ?>

<?php
if (isset($pdo)) {
    require_once __DIR__ . '/kb_functions.php';
    if (isITStaff($pdo)) {
?>
                <a href="assets.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-laptop"></i>

                    Asset Tracking

                </a>

                <a href="knowledge-base.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-book"></i>

                    IT Knowledge Base

                </a>

<?php
    }
}
?>

                <a href="announcements.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-megaphone"></i>

                    Announcements

                </a>

                <a href="documents.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-folder"></i>

                    Documents

                </a>

                <a href="upload-center.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-cloud-upload"></i>

                    Upload Center

                </a>

                <a href="activity-logs.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-clock-history"></i>

                    Activity Logs

                </a>

                <a href="settings.php"
                   class="list-group-item list-group-item-action bg-dark text-white border-secondary">

                    <i class="bi bi-gear"></i>

                    Settings

                </a>

            </div>

        </div>

        <!-- Main Content -->

        <div class="col-md-10 p-4">