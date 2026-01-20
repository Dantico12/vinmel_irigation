<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// Get current user info
$current_user = getCurrentUser();

// If getCurrentUser() returns null, try to get user from session
if (!$current_user && isset($_SESSION['user_id'])) {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT * FROM users WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_user = $result->fetch_assoc();
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Handle API request for user data
if (isset($_GET['api']) && $_GET['api'] == 'get_user' && isset($_GET['id'])) {
    $user_id = intval($_GET['id']);
    
    $query = "SELECT * FROM users WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        // Remove password from response
        unset($user['password']);
        header('Content-Type: application/json');
        echo json_encode($user);
        exit();
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit();
    }
}

// Handle form actions
$action = isset($_GET['action']) ? $_GET['action'] : '';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Add new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Validate inputs
    if (empty($name) || empty($email) || empty($_POST['password'])) {
        $error_message = "All required fields must be filled!";
    } elseif (strlen($_POST['password']) < 6) {
        $error_message = "Password must be at least 6 characters long!";
    } else {
        // Check if email already exists
        $check_email = "SELECT id FROM users WHERE email = ?";
        $check_stmt = $db->prepare($check_email);
        $check_stmt->bind_param('s', $email);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $error_message = "Email already exists!";
        } else {
            $insert_query = "INSERT INTO users (name, email, password, role, is_active) VALUES (?, ?, ?, ?, ?)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bind_param('ssssi', $name, $email, $password, $role, $is_active);
            
            if ($insert_stmt->execute()) {
                $success_message = "User added successfully!";
            } else {
                $error_message = "Error adding user: " . $insert_stmt->error;
            }
        }
    }
}

// Update user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate inputs
    if (empty($name) || empty($email)) {
        $error_message = "Name and email are required fields!";
    } else {
        // If password is provided, update it
        if (!empty($_POST['password'])) {
            if (strlen($_POST['password']) < 6) {
                $error_message = "Password must be at least 6 characters long!";
            } else {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET name = ?, email = ?, password = ?, role = ?, is_active = ? WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bind_param('ssssii', $name, $email, $password, $role, $is_active, $user_id);
            }
        } else {
            $update_query = "UPDATE users SET name = ?, email = ?, role = ?, is_active = ? WHERE id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bind_param('sssii', $name, $email, $role, $is_active, $user_id);
        }
        
        if (!isset($error_message) && $update_stmt->execute()) {
            $success_message = "User updated successfully!";
        } elseif (!isset($error_message)) {
            $error_message = "Error updating user: " . $update_stmt->error;
        }
    }
}

// Delete user
if ($action === 'delete' && $user_id > 0) {
    // Prevent deleting your own account - FIXED: using $current_user with null check
    if ($current_user && $user_id != $current_user['id']) {
        $delete_query = "DELETE FROM users WHERE id = ?";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bind_param('i', $user_id);
        
        if ($delete_stmt->execute()) {
            $success_message = "User deleted successfully!";
        } else {
            $error_message = "Error deleting user: " . $delete_stmt->error;
        }
    } else {
        $error_message = "You cannot delete your own account!";
    }
}

// Toggle user status
if ($action === 'toggle_status' && $user_id > 0) {
    if ($current_user && $user_id != $current_user['id']) {
        $toggle_query = "UPDATE users SET is_active = NOT is_active WHERE id = ?";
        $toggle_stmt = $db->prepare($toggle_query);
        $toggle_stmt->bind_param('i', $user_id);
        
        if ($toggle_stmt->execute()) {
            $success_message = "User status updated successfully!";
        } else {
            $error_message = "Error updating user status: " . $toggle_stmt->error;
        }
    } else {
        $error_message = "You cannot deactivate your own account!";
    }
}

// Get all users
$users_query = "SELECT * FROM users ORDER BY created_at DESC";
$users_result = $db->query($users_query);

// Get user for editing
$edit_user = null;
if ($action === 'edit' && $user_id > 0) {
    $edit_query = "SELECT * FROM users WHERE id = ?";
    $edit_stmt = $db->prepare($edit_query);
    $edit_stmt->bind_param('i', $user_id);
    $edit_stmt->execute();
    $edit_user = $edit_stmt->get_result()->fetch_assoc();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Vinmel Irrigation</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="main-content">
        <?php include 'nav_bar.php'; ?>
        
        <div class="content-area">
            <!-- Page Header -->
            <div class="dashboard-header">
                <div>
                    <h1><i class="fas fa-users-cog"></i> User Management</h1>
                    <p class="text-muted">Manage system users and their permissions</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary" onclick="openModal('addUserModal')">
                        <i class="fas fa-user-plus"></i> Add New User
                    </button>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Users Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> System Users</h5>
                    <div class="text-muted">
                        Total Users: <strong><?php echo $users_result->num_rows; ?></strong>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table" id="users-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($users_result->num_rows > 0): ?>
                                    <?php while ($user = $users_result->fetch_assoc()): ?>
                                        <?php
                                        $status_class = $user['is_active'] ? 'status-active' : 'status-inactive';
                                        $status_text = $user['is_active'] ? 'Active' : 'Inactive';
                                        $role_class = 'role-' . $user['role'];
                                        ?>
                                        <tr>
                                            <td><?php echo $user['id']; ?></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="user-avatar-sm">
                                                        <?php 
                                                        $initials = '';
                                                        $name_parts = explode(' ', $user['name']);
                                                        foreach ($name_parts as $part) {
                                                            $initials .= strtoupper(substr($part, 0, 1));
                                                        }
                                                        echo $initials;
                                                        ?>
                                                    </div>
                                                    <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <span class="role-badge <?php echo $role_class; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="user-status-badge <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?>
                                            </td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                            </td>
                                            <td>
                                                <div class="user-actions">
                                                    <button class="btn btn-sm btn-primary" 
                                                            onclick="editUser(<?php echo $user['id']; ?>)"
                                                            title="Edit User">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if ($current_user && $user['id'] != $current_user['id']): ?>
                                                        <button class="btn btn-sm btn-warning" 
                                                                onclick="toggleStatus(<?php echo $user['id']; ?>)"
                                                                title="<?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                            <i class="fas fa-power-off"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-danger" 
                                                                onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')"
                                                                title="Delete User">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge badge-info">Current User</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5">
                                            <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                            <p class="text-muted">No users found in the system.</p>
                                            <button type="button" class="btn btn-primary" onclick="openModal('addUserModal')">
                                                <i class="fas fa-user-plus"></i> Add Your First User
                                            </button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal-backdrop" id="addUserModalBackdrop"></div>
    <div class="modal" id="addUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus"></i> Add New User
                </h5>
                <button type="button" class="modal-close" onclick="closeModal('addUserModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="name" class="form-control" required 
                               placeholder="Enter full name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Address *</label>
                        <input type="email" name="email" class="form-control" required 
                               placeholder="Enter email address">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password *</label>
                        <input type="password" name="password" class="form-control" required 
                               placeholder="Enter password" minlength="6">
                        <div class="form-text">Password must be at least 6 characters long.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role *</label>
                        <select name="role" class="form-select" required>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="addIsActive" checked>
                        <label class="form-check-label" for="addIsActive">
                            Active User
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addUserModal')">Cancel</button>
                    <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal-backdrop" id="editUserModalBackdrop"></div>
    <div class="modal" id="editUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit"></i> Edit User
                </h5>
                <button type="button" class="modal-close" onclick="closeModal('editUserModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="name" id="editName" class="form-control" required 
                               placeholder="Enter full name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Address *</label>
                        <input type="email" name="email" id="editEmail" class="form-control" required 
                               placeholder="Enter email address">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="password" class="form-control" 
                               placeholder="Leave blank to keep current password" minlength="6">
                        <div class="form-text">Enter new password only if you want to change it.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role *</label>
                        <select name="role" id="editRole" class="form-select" required>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="editIsActive">
                        <label class="form-check-label" for="editIsActive">
                            Active User
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editUserModal')">Cancel</button>
                    <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.getElementById(modalId + 'Backdrop').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.getElementById(modalId + 'Backdrop').classList.remove('show');
            document.body.style.overflow = 'auto';
            
            // Reset add form when closing
            if (modalId === 'addUserModal') {
                document.querySelector('#addUserModal form').reset();
            }
        }

        // Close modal when clicking on backdrop
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-backdrop')) {
                const modalId = e.target.id.replace('Backdrop', '');
                closeModal(modalId);
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const openModals = document.querySelectorAll('.modal.show');
                openModals.forEach(modal => {
                    const modalId = modal.id;
                    closeModal(modalId);
                });
            }
        });

        // Edit user function
        function editUser(userId) {
            fetch('users.php?api=get_user&id=' + userId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(user => {
                    if (user.error) {
                        alert(user.error);
                        return;
                    }
                    document.getElementById('editUserId').value = user.id;
                    document.getElementById('editName').value = user.name;
                    document.getElementById('editEmail').value = user.email;
                    document.getElementById('editRole').value = user.role;
                    document.getElementById('editIsActive').checked = user.is_active == 1;
                    
                    openModal('editUserModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading user data');
                });
        }

        // Toggle user status
        function toggleStatus(userId) {
            if (confirm('Are you sure you want to change this user\'s status?')) {
                window.location.href = 'users.php?action=toggle_status&id=' + userId;
            }
        }

        // Confirm user deletion
        function confirmDelete(userId, userName) {
            if (confirm('Are you sure you want to delete user "' + userName + '"? This action cannot be undone.')) {
                window.location.href = 'users.php?action=delete&id=' + userId;
            }
        }

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });
    </script>
</body>
</html>