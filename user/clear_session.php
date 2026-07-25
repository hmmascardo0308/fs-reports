<?php
// clear_session.php
session_start();

// Clear all upload-related session data
unset($_SESSION['parsed_data']);
unset($_SESSION['uploaded_headers']);
unset($_SESSION['total_rows']);
unset($_SESSION['file_name']);
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
unset($_SESSION['summary_data']);
unset($_SESSION['column_mapping']);
unset($_SESSION['remarks_data']);
unset($_SESSION['skipped_data']);
unset($_SESSION['clear_on_next_load']);

// Return a simple response
header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'message' => 'Session cleared']);
?>