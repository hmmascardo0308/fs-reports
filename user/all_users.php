<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// Session Management
if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$username  = $_SESSION['username'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

$message = "";
$message_type = ""; // 'success' or 'error'

// Handle success messages
if (isset($_GET['reg_success'])) {
    $message = "User registered successfully.";
    $message_type = "success";
}
if (isset($_GET['delete_success'])) {
    $message = "User deleted successfully.";
    $message_type = "success";
}
if (isset($_GET['update_success'])) {
    $message = "User updated successfully.";
    $message_type = "success";
}

// Handle new user registration
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['register_user'])) {
    $id_number   = trim($_POST['id_number'] ?? '');
    $first_name  = trim(strtoupper($_POST['first_name'] ?? '')); 
    $middle_name = trim(strtoupper($_POST['middle_name'] ?? '')); 
    $last_name   = trim(strtoupper($_POST['last_name'] ?? ''));  
    $full_name_reg = trim($_POST['full_name'] ?? '');
    $username_reg  = trim($_POST['username'] ?? '');
    $password_reg  = $_POST['password'] ?? 'Mlinc1234';
    $user_type_reg = $_POST['user_type'] ?? '';

    if (empty($id_number) || empty($first_name) || empty($last_name) || empty($username_reg) || empty($user_type_reg)) {
        $message = "All required fields must be filled.";
        $message_type = "error";
    } else {
        $check_username = $conn->prepare("SELECT id FROM qcl_user WHERE username = ?");
        $check_username->bind_param("s", $username_reg);
        $check_username->execute();
        $check_username->store_result();

        if ($check_username->num_rows > 0) {
            $message = "Username already exists.";
            $message_type = "error";
        } else {
            $hashed_password = password_hash($password_reg, PASSWORD_DEFAULT);
            $insert_sql = "INSERT INTO qcl_user (id_number, first_name, middle_name, last_name, full_name, username, password, user_type, created_at, last_online) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ssssssss", $id_number, $first_name, $middle_name, $last_name, $full_name_reg, $username_reg, $hashed_password, $user_type_reg);

            if ($insert_stmt->execute()) {
                header("Location: " . $_SERVER['PHP_SELF'] . "?reg_success=1");
                exit;
            } else {
                $message = "Failed to register user.";
                $message_type = "error";
            }
        }
    }
}

// Handle user update (NEW)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_user'])) {
    $user_id     = $_POST['edit_user_id']     ?? '';
    $id_number   = trim($_POST['edit_id_number']   ?? '');
    $first_name  = trim(strtoupper($_POST['edit_first_name']  ?? ''));
    $middle_name = trim(strtoupper($_POST['edit_middle_name'] ?? ''));
    $last_name   = trim(strtoupper($_POST['edit_last_name']   ?? ''));
    $full_name   = trim($_POST['edit_full_name']   ?? '');
    $username    = trim($_POST['edit_username']    ?? '');

    if (empty($user_id) || empty($id_number) || empty($first_name) || empty($last_name) || empty($username)) {
        $message = "Required fields are missing.";
        $message_type = "error";
    } else {
        // Check if username is taken by someone else
        $check = $conn->prepare("SELECT id FROM qcl_user WHERE username = ? AND id != ?");
        $check->bind_param("si", $username, $user_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $message = "Username is already taken by another user.";
            $message_type = "error";
        } else {
            $update_sql = "UPDATE qcl_user SET 
                id_number   = ?,
                first_name  = ?,
                middle_name = ?,
                last_name   = ?,
                full_name   = ?,
                username    = ?,
                updated_at  = NOW()
            WHERE id = ?";

            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ssssssi", $id_number, $first_name, $middle_name, $last_name, $full_name, $username, $user_id);

            if ($stmt->execute()) {
                header("Location: " . $_SERVER['PHP_SELF'] . "?update_success=1");
                exit;
            } else {
                $message = "Failed to update user.";
                $message_type = "error";
            }
        }
    }
}

// Handle delete user
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'] ?? '';
    $current_username = $_SESSION['username'] ?? '';
    
    $check_self = $conn->prepare("SELECT username FROM qcl_user WHERE id = ?");
    $check_self->bind_param("i", $user_id);
    $check_self->execute();
    $result_self = $check_self->get_result();
    
    if ($row_self = $result_self->fetch_assoc()) {
        if ($row_self['username'] === $current_username) {
            $message = "You cannot delete your own account.";
            $message_type = "error";
        } else {
            $delete_stmt = $conn->prepare("DELETE FROM qcl_user WHERE id = ?");
            $delete_stmt->bind_param("i", $user_id);
            if ($delete_stmt->execute()) {
                header("Location: " . $_SERVER['PHP_SELF'] . "?delete_success=1");
                exit;
            } else {
                $message = "Failed to delete user.";
                $message_type = "error";
            }
        }
    }
}

$sql = "SELECT * FROM qcl_user ORDER BY id ASC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>All Users</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="css/all_users.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
</head>
<body>

    <?php if (!empty($message)): ?>
        <div id="toast" class="message-popup <?php echo $message_type; ?>">
            <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <main class="main-content">
        <header class="top-bar">
            <h2><a href="user_dashboard.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge" style="font-weight: bold;">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="page-header">
                <h2>All Users</h2>
                <button id="createUserBtn" class="create-btn"><i class="fa-solid fa-user-plus"></i> Create New User</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th><i class="fas fa-hashtag"></i> #</th>
                        <th><i class="fas fa-id-card"></i> ID Number</th>
                        <th><i class="fas fa-user"></i> Full Name</th>
                        <th><i class="fas fa-at"></i> Username</th>
                        <th><i class="fas fa-user-tag"></i> User Type</th>
                        <th><i class="fas fa-calendar-plus"></i> Created Date</th>
                        <th><i class="fas fa-clock"></i> Last Online</th>
                        <th><i class="fas fa-cogs"></i> Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result->num_rows > 0) {
                        $counter = 1;
                        while ($row = $result->fetch_assoc()) {
                            echo "<tr data-userid='" . $row["id"] . "'
                                  data-idnumber='" . htmlspecialchars($row["id_number"]) . "'
                                  data-first='" . htmlspecialchars($row["first_name"]) . "'
                                  data-middle='" . htmlspecialchars($row["middle_name"]) . "'
                                  data-last='" . htmlspecialchars($row["last_name"]) . "'
                                  data-full='" . htmlspecialchars($row["full_name"]) . "'
                                  data-username='" . htmlspecialchars($row["username"]) . "'
                                  data-usertype='" . htmlspecialchars($row["user_type"]) . "'>
                                <td style='text-align: center;'>" . $counter++ . "</td>
                                <td>" . htmlspecialchars($row["id_number"]) . "</td>
                                <td>" . htmlspecialchars($row["full_name"]) . "</td>
                                <td>" . htmlspecialchars($row["username"]) . "</td>
                                <td>" . htmlspecialchars($row["user_type"]) . "</td>
                                <td>" . htmlspecialchars($row["created_at"]) . "</td>
                                <td>" . ($row["last_online"] ? htmlspecialchars($row["last_online"]) : 'Never') . "</td>
                                <td>
                                    <div class='action-buttons'>
                                        <button class='btn-delete' onclick='event.stopPropagation(); showDeleteModal(" . $row["id"] . ", \"" . htmlspecialchars(addslashes($row["full_name"])) . "\", \"" . htmlspecialchars(addslashes($row["username"])) . "\")'><i class='fas fa-trash'></i> </button>
                                    </div>
                                </td>
                            </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='8'>No users found</td></tr>";
                    } 
                    ?>
                </tbody>
            </table>
            <div class="footer-note">
                Note: Double click row to edit user
            </div>
        </div>
    </main>

    <!-- Create Modal -->
    <div id="createUserModal" class="modal">
        <div class="modal-content">
            <span class="close" id="closeCreate">&times;</span>
            <h2>Create New User</h2>
            <form method="post">
                <label for="id_number">ID Number</label>
                <input type="text" id="id_number" name="id_number" required>
                <div style="display: flex; gap: 10px;">
                    <div>
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                    </div>
                    <div>
                        <label for="middle_name">Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                    </div>
                    <div>
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                    </div>
                </div>
                <input type="hidden" id="full_name" name="full_name">
                <label for="username">Username (Auto-generated)</label>
                <input type="text" id="username" name="username" readonly style="background-color: #f0f0f0;">
                <label for="password">Password</label>
                <div style="position: relative;">
                    <input type="password" id="password" name="password" value="Mlinc1234" readonly style="width: 100%; padding-right: 40px; background-color: #f0f0f0;">
                    <i class="fas fa-eye" id="togglePassword" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #666;"></i>
                </div>
                <small style="display: block; margin-top: 5px; color: #666;">Default password: Mlinc1234 (read-only)</small>
                <label for="user_type">User Type</label>
                <select id="user_type" name="user_type" required>
                    <option value="">Select User Type</option>
                    <option value="admin">Admin</option>
                    <option value="reports">Reports</option>
                    <option value="bookkeeper">Bookkeeper</option>
                </select>
                <button type="submit" name="register_user"><i class="fa-solid fa-floppy-disk"></i> Register User</button>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <span class="close" id="closeEdit">&times;</span>
            <h2>Edit User</h2>
            <form method="post" id="editUserForm">
                <input type="hidden" name="edit_user_id" id="edit_user_id">

                <label for="edit_id_number">ID Number</label>
                <input type="text" id="edit_id_number" name="edit_id_number" required>

                <div style="display: flex; gap: 10px;">
                    <div style="flex: 1;">
                        <label for="edit_first_name">First Name</label>
                        <input type="text" id="edit_first_name" name="edit_first_name" required 
                               style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                    </div>
                    <div style="flex: 1;">
                        <label for="edit_middle_name">Middle Name</label>
                        <input type="text" id="edit_middle_name" name="edit_middle_name" 
                               style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                    </div>
                    <div style="flex: 1;">
                        <label for="edit_last_name">Last Name</label>
                        <input type="text" id="edit_last_name" name="edit_last_name" required 
                               style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
                    </div>
                </div>

                <input type="hidden" id="edit_full_name" name="edit_full_name">

                <label for="edit_username">Username</label>
                <input type="text" id="edit_username" name="edit_username" required>

                <label>User Type</label>
                <input type="text" id="edit_user_type_display" readonly style="background-color: #f0f0f0;">

                <button type="submit" name="update_user"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteUserModal" class="modal modal-sm" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> Delete User</h2>
                <span class="close" onclick="closeDeleteModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="warning-text"><strong>Warning!</strong> This action cannot be undone.</div>
                <div class="user-info-delete">
                    <p><strong>Full Name:</strong> <span id="deleteUserName"></span></p>
                    <p><strong>Username:</strong> <span id="deleteUserUsername"></span></p>
                </div>
                <p>Are you sure you want to delete this user?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                <form method="post" style="display: inline;" id="deleteUserForm">
                    <input type="hidden" name="user_id" id="deleteUserId" value="">
                    <button type="submit" name="delete_user" class="btn-confirm-delete">Yes, Delete</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // ──────────────────────────────────────────────
        // Create Modal
        // ──────────────────────────────────────────────
        const createModal = document.getElementById("createUserModal");
        const createBtn = document.getElementById("createUserBtn");
        const closeCreate = document.getElementById("closeCreate");

        createBtn.onclick = () => createModal.style.display = "flex";
        closeCreate.onclick = () => createModal.style.display = "none";

        // ──────────────────────────────────────────────
        // Edit Modal
        // ──────────────────────────────────────────────
        const editModal = document.getElementById("editUserModal");
        const closeEdit = document.getElementById("closeEdit");

        closeEdit.onclick = () => editModal.style.display = "none";

        function showEditModal(userId, idNumber, first, middle, last, full, username, userType) {
            document.getElementById('edit_user_id').value         = userId;
            document.getElementById('edit_id_number').value       = idNumber;
            document.getElementById('edit_first_name').value      = first;
            document.getElementById('edit_middle_name').value     = middle || '';
            document.getElementById('edit_last_name').value       = last;
            document.getElementById('edit_full_name').value       = full;
            document.getElementById('edit_username').value        = username;
            document.getElementById('edit_user_type_display').value = userType;
            editModal.style.display = "flex";
        }

        // Auto-update full name in edit modal
        const editF = document.getElementById('edit_first_name');
        const editM = document.getElementById('edit_middle_name');
        const editL = document.getElementById('edit_last_name');
        const editFullHidden = document.getElementById('edit_full_name');

        function updateEditFullName() {
            const full = [editF.value, editM.value, editL.value]
                .filter(Boolean)
                .join(' ')
                .trim();
            editFullHidden.value = full;
        }

        [editF, editM, editL].forEach(el => el.addEventListener('input', updateEditFullName));

        // ──────────────────────────────────────────────
        // Delete Modal
        // ──────────────────────────────────────────────
        function showDeleteModal(userId, fullName, username) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = fullName;
            document.getElementById('deleteUserUsername').textContent = username;
            document.getElementById('deleteUserModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteUserModal').style.display = 'none';
        }

        // ──────────────────────────────────────────────
        // Double-click to edit + prevent delete button conflict
        // ──────────────────────────────────────────────
        document.querySelectorAll('tbody tr[data-userid]').forEach(row => {
            row.addEventListener('dblclick', function(e) {
                // Don't open edit if clicking delete button
                if (e.target.closest('.btn-delete')) return;

                const id       = this.dataset.userid;
                const idNum    = this.dataset.idnumber;
                const first    = this.dataset.first;
                const middle   = this.dataset.middle;
                const last     = this.dataset.last;
                const full     = this.dataset.full;
                const username = this.dataset.username;
                const utype    = this.dataset.usertype;

                showEditModal(id, idNum, first, middle, last, full, username, utype);
            });
        });

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target === createModal) createModal.style.display = "none";
            if (event.target === editModal)   editModal.style.display   = "none";
            if (event.target === document.getElementById('deleteUserModal')) {
                document.getElementById('deleteUserModal').style.display = "none";
            }
        };

        // ──────────────────────────────────────────────
        // Create modal auto-username + password toggle
        // ──────────────────────────────────────────────
        const idInput = document.getElementById('id_number');
        const fName = document.getElementById('first_name');
        const mName = document.getElementById('middle_name');
        const lName = document.getElementById('last_name');
        const fullNameHidden = document.getElementById('full_name');
        const usernameInput = document.getElementById('username');

        function updateAutofills() {
            const full = [fName.value, mName.value, lName.value]
                .filter(Boolean)
                .join(' ')
                .trim();
            fullNameHidden.value = full;

            let lastFour = (lName.value || '').substring(0, 4).toUpperCase();
            let idVal = idInput.value.trim();
            usernameInput.value = (lastFour && idVal) ? lastFour + idVal : "";
        }

        [idInput, fName, mName, lName].forEach(el => el.addEventListener('input', updateAutofills));

        // Password visibility toggle (create modal)
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.getElementById('togglePassword');
        toggleIcon.addEventListener('click', () => {
            const type = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = type;
            toggleIcon.classList.toggle('fa-eye');
            toggleIcon.classList.toggle('fa-eye-slash');
        });

        // ──────────────────────────────────────────────
        // Toast auto-dismiss + clean URL
        // ──────────────────────────────────────────────
        document.addEventListener("DOMContentLoaded", function() {
            const toast = document.getElementById('toast');
            if (toast) {
                setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => {
                        window.location.href = window.location.pathname;
                    }, 500);
                }, 3000);
            }
        });
    </script>

<?php include '../footer.php'; ?>

</body>
</html>
<?php $conn->close(); ?>