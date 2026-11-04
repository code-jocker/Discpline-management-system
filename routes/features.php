<?php

/**
 * SBMS Feature Routes (v1 API)
 * Include this file in bootstrap/api.php
 * 
 * Usage in bootstrap/api.php:
 * include __DIR__ . '/../routes/features.php';
 */

use App\Controllers\RewardsController;
use App\Controllers\CollaborativeController;
use App\Controllers\CalendarController;
use App\Controllers\AnalyticsController;

// Initialize services
$rewards = new \App\Services\RewardsService($pdo, $parentNotifications);
$collaborative = new \App\Services\CollaborativeService($pdo);
$calendar = new \App\Services\CalendarService($pdo);
$reportService = new \App\Services\ReportService($pdo);

// Initialize controllers
$rewardsCtrl = new RewardsController($rewards);
$collaborativeCtrl = new CollaborativeController($collaborative, $parentNotifications);
$calendarCtrl = new CalendarController($calendar);
$analyticsCtrl = new AnalyticsController($reportService);

// ==========================================
// REWARDS & GAMIFICATION ROUTES
// ==========================================

$router->get('/students/:id/achievements', function() use ($rewardsCtrl) {
    $rewardsCtrl->getAchievements();
});

$router->get('/classes/:id/leaderboard', function() use ($rewardsCtrl) {
    $rewardsCtrl->getLeaderboard();
});

$router->post('/leaderboards/build', function() use ($rewardsCtrl) {
    $rewardsCtrl->buildLeaderboard();
});

// ==========================================
// COLLABORATIVE TOOLS ROUTES
// ==========================================

$router->get('/incidents/:id/details', function() use ($collaborativeCtrl) {
    $collaborativeCtrl->getIncidentDetails();
});

$router->post('/incidents/:id/comments', function() use ($collaborativeCtrl) {
    $collaborativeCtrl->addComment();
});

$router->post('/incidents/:id/actions', function() use ($collaborativeCtrl) {
    $collaborativeCtrl->createAction();
});

$router->get('/users/:id/pending-actions', function() use ($collaborativeCtrl) {
    $collaborativeCtrl->getPendingActions();
});

$router->patch('/actions/:id/complete', function() use ($collaborativeCtrl) {
    $collaborativeCtrl->completeAction();
});

$router->patch('/actions/:id/status', function() use ($collaborativeCtrl) {
    $collaborativeCtrl->updateActionStatus();
});

// ==========================================
// CALENDAR ROUTES
// ==========================================

$router->get('/calendar/events', function() use ($calendarCtrl) {
    $calendarCtrl->getEvents();
});

$router->get('/calendar/month', function() use ($calendarCtrl) {
    $calendarCtrl->getMonthView();
});

$router->get('/calendar/upcoming', function() use ($calendarCtrl) {
    $calendarCtrl->getUpcomingEvents();
});

$router->get('/students/:id/calendar-events', function() use ($calendarCtrl) {
    $calendarCtrl->getStudentEvents();
});

$router->post('/calendar/events', function() use ($calendarCtrl) {
    $calendarCtrl->createEvent();
});

$router->delete('/calendar/events/:id', function() use ($calendarCtrl) {
    $calendarCtrl->deleteEvent();
});

// ==========================================
// ANALYTICS & REPORTING ROUTES
// ==========================================

$router->get('/analytics/dashboard', function() use ($analyticsCtrl) {
    $analyticsCtrl->getDashboardAnalytics();
});

$router->get('/analytics/incidents', function() use ($analyticsCtrl) {
    $analyticsCtrl->getIncidentAnalytics();
});

$router->post('/reports/term', function() use ($analyticsCtrl) {
    $analyticsCtrl->generateTermReport();
});

$router->post('/reports/class-progress', function() use ($analyticsCtrl) {
    $analyticsCtrl->generateClassProgressReport();
});
