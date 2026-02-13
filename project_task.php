<?php /* Project tasks page: tasks listing at mga actions. */
require_once 'includes/auth_guard.php';

$page_title = 'Project Workspace';
$current_page = basename(__FILE__);


require_once 'config/database.php';


$form_message = null;
$pdo = null;
  try {
    $pdo = getDBConnection();
  } catch (Throwable $e) {
    $form_message = 'Database connection error. Please check configuration.';
  }

  // Ensure pivot table exists for multi-user assignment
  if ($pdo) {
    try {
      $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `task_assignees` (
          `task_id` INT NOT NULL,
          `user_id` INT NOT NULL,
          `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`task_id`,`user_id`),
          KEY `idx_task_assignees_user_id` (`user_id`),
          CONSTRAINT `fk_task_assignees_task` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
          CONSTRAINT `fk_task_assignees_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
      );
    } catch (Throwable $e) { /* ignore */ }

    try {
      $st = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'project_manager_id'");
      $exists = (int)($st ? $st->fetchColumn() : 0);
      if ($exists === 0) {
        $pdo->exec("ALTER TABLE `projects` ADD COLUMN `project_manager_id` INT NULL AFTER `due_date`");
        try { $pdo->exec("ALTER TABLE `projects` ADD KEY `idx_projects_project_manager_id` (`project_manager_id`)"); } catch (Throwable $_) {}
        try { $pdo->exec("ALTER TABLE `projects` ADD CONSTRAINT `fk_projects_project_manager` FOREIGN KEY (`project_manager_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE"); } catch (Throwable $_) {}
      }
    } catch (Throwable $_) {}

    try {
      $pdo->exec("INSERT IGNORE INTO project_statuses(`key`,`name`,`order_index`,`active`) VALUES
        ('planning','Planning',0,1),
        ('active','Active',1,1),
        ('at-risk','At Risk',2,1),
        ('on-hold','On Hold',3,1),
        ('completed','Completed',4,1)");
    } catch (Throwable $_) {}

    try {
      $pdo->exec("INSERT IGNORE INTO task_statuses(`key`,`name`,`order_index`,`active`) VALUES
        ('todo','To Do',0,1),
        ('in-progress','In Progress',1,1),
        ('review','In Review',2,1),
        ('done','Done',3,1)");
    } catch (Throwable $_) {}
  }

if (!empty($_SESSION['flash_message'])) {
  $form_message = $_SESSION['flash_message'];
  unset($_SESSION['flash_message']);
}
  if ($pdo) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $action = $_POST['action'] ?? '';
      if ($action === 'create_project') {
      $name = trim($_POST['name'] ?? '');
      $description = trim($_POST['description'] ?? '');
      $category = trim($_POST['category'] ?? '');
      $priority = $_POST['priority'] ?? 'medium';
      $status = $_POST['status'] ?? 'active';
      $project_manager_id = !empty($_POST['project_manager_id']) ? (int)$_POST['project_manager_id'] : null;
      $team_id = !empty($_POST['team_id']) ? (int)$_POST['team_id'] : null;
      $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
      $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
      if ($name && $due_date) {
        try {
          $skey = $status ?: 'planning';
          $stmt = $pdo->prepare('SELECT id FROM project_statuses WHERE `key` = ? LIMIT 1');
          $stmt->execute([$skey]);
          $sid = $stmt->fetchColumn() ?: null;
          if (!$sid) {
            $stmt->execute(['planning']);
            $sid = $stmt->fetchColumn() ?: null;
          }
          $stmt = $pdo->prepare('INSERT INTO projects (name, description, category, priority, project_status_id, team_id, project_manager_id, start_date, due_date, progress, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)');
          $stmt->execute([$name, $description, $category, $priority, $sid, $team_id, $project_manager_id, $start_date, $due_date, $_SESSION['user_id'] ?? null]);
          $_SESSION['flash_message'] = 'Project created successfully.';
          header('Location: project_task.php');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to create project: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Please fill in required fields (name, due date).';
      }
    } elseif ($action === 'create_task') {
      $title = trim($_POST['title'] ?? '');
      $description = trim($_POST['description'] ?? '');
      $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
      $category = trim($_POST['category'] ?? '');
      $assignee_id = !empty($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : null;
      $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
      $priority = $_POST['priority'] ?? 'medium';
      $status = $_POST['status'] ?? 'todo';
      $parent_task_id = !empty($_POST['parent_task_id']) ? (int)$_POST['parent_task_id'] : null;
      if ($title && $project_id) {
        try {
          $tkey = $status ?: 'todo';
          $stmt = $pdo->prepare('SELECT id FROM task_statuses WHERE `key` = ? LIMIT 1');
          $stmt->execute([$tkey]);
          $tid = $stmt->fetchColumn() ?: null;
          if (!$tid) {
            $stmt->execute(['todo']);
            $tid = $stmt->fetchColumn() ?: null;
          }
          $stmt = $pdo->prepare('INSERT INTO tasks (project_id, title, description, category, priority, assignee_id, due_date, task_status_id, parent_task_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
          $stmt->execute([$project_id, $title, $description, $category, $priority, $assignee_id, $due_date, $tid, $parent_task_id]);
          if ($assignee_id) {
            try {
              $stp = $pdo->prepare('SELECT name FROM projects WHERE id = ? LIMIT 1');
              $stp->execute([$project_id]);
              $pn = $stp->fetchColumn();
              $tt = 'Task Assigned: ' . $title;
              $bd = ($pn ? ('Project: ' . $pn . "\n") : '') . ($description ?: '');
              $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read) VALUES (?, ?, ?, ?, 0)')->execute([$assignee_id, $tt, $bd, 'project_task.php#pane-kanban']);
            } catch (Throwable $_) {}
          }
          $_SESSION['flash_message'] = 'Task created successfully.';
          header('Location: project_task.php#pane-kanban');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to create task: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Please fill in required fields (title, project).';
      }
    } elseif ($action === 'create_extension_request') {
      $task_title = trim($_POST['task_title'] ?? '');
      $project_name = trim($_POST['project_name'] ?? '');
      $current_due = $_POST['current_due_date'] ?? null;
      $requested_due = $_POST['requested_due_date'] ?? null;
      $reason = trim($_POST['reason'] ?? '');
      $justification = trim($_POST['justification'] ?? '');
      $priority = $_POST['priority'] ?? 'medium';
      $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : null;
      $requested_by = $_SESSION['user_id'] ?? null;
      if ($requested_by && $current_due && $requested_due) {
        $diffDays = null;
        try { $diffDays = (new DateTime($requested_due))->diff(new DateTime($current_due))->days; } catch (Throwable $e) { $diffDays = null; }
        try {
          $stmt = $pdo->prepare('INSERT INTO extension_requests (task_id, requested_by, current_due_date, requested_due_date, requested_extension_days, reason, justification, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
          $stmt->execute([$task_id, $requested_by, $current_due, $requested_due, $diffDays ?? 0, $reason, $justification, $priority]);
          try {
            $stt = $pdo->prepare('SELECT t.title, t.project_id, p.project_manager_id, p.name FROM tasks t LEFT JOIN projects p ON p.id = t.project_id WHERE t.id = ?');
            $stt->execute([$task_id]);
            $row = $stt->fetch();
            $pm = (int)($row['project_manager_id'] ?? 0);
            $tt = (string)($row['title'] ?? 'Task');
            $pn = (string)($row['name'] ?? 'Project');
            if ($pm) {
              $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read) VALUES (?, ?, ?, ?, 0)')
                  ->execute([$pm, 'Extension Requested: ' . $tt, 'Project: ' . $pn, 'project_task.php#pane-extensions']);
            }
          } catch (Throwable $_) {}
          $_SESSION['flash_message'] = 'Extension request submitted.';
          header('Location: project_task.php#pane-extensions');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to submit extension request: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Please fill in required fields and ensure you are logged in.';
      }
    } elseif ($action === 'update_task_status') {
      $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
      $new_status = $_POST['new_status'] ?? '';
      $allowed = ['todo','in-progress','review','done'];
      if ($task_id > 0 && in_array($new_status, $allowed, true)) {
        try {
          $stmt = $pdo->prepare('SELECT id FROM task_statuses WHERE `key` = ? LIMIT 1');
          $stmt->execute([$new_status]);
          $tid = $stmt->fetchColumn() ?: null;
          $stmt = $pdo->prepare('UPDATE tasks SET task_status_id = ? WHERE id = ?');
          $stmt->execute([$tid, $task_id]);
          try {
            $stt = $pdo->prepare('SELECT assignee_id, title, project_id FROM tasks WHERE id = ? LIMIT 1');
            $stt->execute([$task_id]);
            $row = $stt->fetch();
            $ass = (int)($row['assignee_id'] ?? 0);
            $tt = (string)($row['title'] ?? 'Task');
            $pid = (int)($row['project_id'] ?? 0);
            $statusLabel = $new_status;
            if ($ass) {
              $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read) VALUES (?, ?, ?, ?, 0)')
                  ->execute([$ass, 'Task Updated: ' . $tt, 'Status changed to ' . $statusLabel, 'project_task.php#pane-kanban']);
            }
            if ($statusLabel === 'done' && $pid) {
              $spm = $pdo->prepare('SELECT project_manager_id, name FROM projects WHERE id = ?');
              $spm->execute([$pid]);
              $prow = $spm->fetch();
              $pm = (int)($prow['project_manager_id'] ?? 0);
              $pn = (string)($prow['name'] ?? 'Project');
              if ($pm) {
                $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read) VALUES (?, ?, ?, ?, 0)')
                    ->execute([$pm, 'Task Completed: ' . $tt, 'Project: ' . $pn, 'project_task.php#pane-kanban']);
              }
            }
          } catch (Throwable $_) {}
          $_SESSION['flash_message'] = 'Task moved to ' . $new_status . '.';
          header('Location: project_task.php#pane-kanban');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to update task status: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Invalid task or status.';
      }
    } elseif ($action === 'add_comment') {
      $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
      $body = trim($_POST['comment_text'] ?? '');
      $user_id = $_SESSION['user_id'] ?? null;
      if ($task_id > 0 && $user_id && $body) {
        try {
          $stmt = $pdo->prepare('INSERT INTO task_comments (task_id, user_id, body) VALUES (?, ?, ?)');
          $stmt->execute([$task_id, $user_id, $body]);
          try {
            $stt = $pdo->prepare('SELECT assignee_id, title FROM tasks WHERE id = ? LIMIT 1');
            $stt->execute([$task_id]);
            $row = $stt->fetch();
            $ass = (int)($row['assignee_id'] ?? 0);
            $tt = (string)($row['title'] ?? 'Task');
            if ($ass && $ass !== (int)$user_id) {
              $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read) VALUES (?, ?, ?, ?, 0)')
                  ->execute([$ass, 'New Comment: ' . $tt, $body, 'project_task.php#pane-kanban']);
            }
          } catch (Throwable $_) {}
          $_SESSION['flash_message'] = 'Comment added.';
          header('Location: project_task.php#pane-kanban');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to add comment: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Please provide a valid comment.';
      }
    } elseif ($action === 'log_time') {
      $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
      $hours = isset($_POST['hours']) ? (float)$_POST['hours'] : 0.0;
      $notes = trim($_POST['notes'] ?? '');
      $user_id = $_SESSION['user_id'] ?? null;
      if ($task_id > 0 && $user_id && $hours > 0) {
        try {
          $stmt = $pdo->prepare('INSERT INTO task_time_logs (task_id, user_id, hours, notes) VALUES (?, ?, ?, ?)');
          $stmt->execute([$task_id, $user_id, $hours, $notes]);
          $_SESSION['flash_message'] = 'Time logged.';
          header('Location: project_task.php#pane-kanban');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to log time: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Please provide valid hours.';
      }
    } elseif ($action === 'mark_blocked') {
      $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
      $reason = trim($_POST['reason'] ?? '');
      if ($task_id > 0 && $reason) {
        try {
          $stmt = $pdo->prepare('UPDATE tasks SET is_blocked = 1, blocked_reason = ?, blocked_at = NOW() WHERE id = ?');
          $stmt->execute([$reason, $task_id]);
          try {
            $stt = $pdo->prepare('SELECT assignee_id, title, project_id FROM tasks WHERE id = ?');
            $stt->execute([$task_id]);
            $row = $stt->fetch();
            $ass = (int)($row['assignee_id'] ?? 0);
            $tt = (string)($row['title'] ?? 'Task');
            $pid = (int)($row['project_id'] ?? 0);
            if ($ass) {
              $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read) VALUES (?, ?, ?, ?, 0)')
                  ->execute([$ass, 'Task Blocked: ' . $tt, $reason, 'project_task.php#pane-kanban']);
            }
            if ($pid) {
              $spm = $pdo->prepare('SELECT project_manager_id, name FROM projects WHERE id = ?');
              $spm->execute([$pid]);
              $prow = $spm->fetch();
              $pm = (int)($prow['project_manager_id'] ?? 0);
              $pn = (string)($prow['name'] ?? 'Project');
              if ($pm) {
                $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read) VALUES (?, ?, ?, ?, 0)')
                    ->execute([$pm, 'Task Blocked: ' . $tt, 'Project: ' . $pn, 'project_task.php#pane-kanban']);
              }
            }
          } catch (Throwable $_) {}
          $_SESSION['flash_message'] = 'Task marked as blocked.';
          header('Location: project_task.php#pane-kanban');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to mark blocked: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Please provide a reason.';
      }
    } elseif ($action === 'update_project') {
      $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
      $name = trim($_POST['name'] ?? '');
      $description = trim($_POST['description'] ?? '');
      $category = trim($_POST['category'] ?? '');
      $priority = $_POST['priority'] ?? 'medium';
      $status = $_POST['status'] ?? 'active';
      $project_manager_id = !empty($_POST['project_manager_id']) ? (int)$_POST['project_manager_id'] : null;
      $team_id = !empty($_POST['team_id']) ? (int)$_POST['team_id'] : null;
      $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
      $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
      if ($project_id > 0 && $name && $due_date) {
        try {
          $skey = $status ?: 'planning';
          $stmt = $pdo->prepare('SELECT id FROM project_statuses WHERE `key` = ? LIMIT 1');
          $stmt->execute([$skey]);
          $sid = $stmt->fetchColumn() ?: null;
          if (!$sid) {
            $stmt->execute(['planning']);
            $sid = $stmt->fetchColumn() ?: null;
          }
          $stmt = $pdo->prepare('UPDATE projects SET name = ?, description = ?, category = ?, priority = ?, project_status_id = ?, team_id = ?, project_manager_id = ?, start_date = ?, due_date = ? WHERE id = ?');
          $stmt->execute([$name, $description, $category, $priority, $sid, $team_id, $project_manager_id, $start_date, $due_date, $project_id]);
          $_SESSION['flash_message'] = 'Project updated successfully.';
          header('Location: project_task.php#pane-projects');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to update project: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Please fill in required fields (name, due date).';
      }
    } elseif ($action === 'delete_project') {
      $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
      if ($project_id > 0) {
        try {
          $stmt = $pdo->prepare('DELETE FROM projects WHERE id = ?');
          $stmt->execute([$project_id]);
          $_SESSION['flash_message'] = 'Project deleted.';
          header('Location: project_task.php#pane-projects');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to delete project: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Invalid project.';
      }
    } elseif ($action === 'update_extension_status') {
      $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
      $new_status = $_POST['new_status'] ?? '';
      $allowed = ['pending','approved','rejected'];
      if ($request_id > 0 && in_array($new_status, $allowed, true)) {
        try {
          $stmt = $pdo->prepare('UPDATE extension_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
          $stmt->execute([$new_status, $_SESSION['user_id'] ?? null, $request_id]);
          $_SESSION['flash_message'] = 'Request ' . $new_status . '.';
          header('Location: project_task.php#pane-extensions');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to update request: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Invalid request or status.';
      }
    } elseif ($action === 'update_task') {
      $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
      $title = trim($_POST['title'] ?? '');
      $description = trim($_POST['description'] ?? '');
      $category = trim($_POST['category'] ?? '');
      $priority = trim($_POST['priority'] ?? 'medium');
      $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
      $statusKey = trim(strtolower($_POST['status'] ?? ''));
      if ($priority === 'normal') { $priority = 'medium'; }
      if ($statusKey === 'completed') { $statusKey = 'done'; }
      if ($title === '') {
        $form_message = 'Task title cannot be empty.';
      } elseif ($description === '') {
        $form_message = 'Task description cannot be empty.';
      } elseif ($task_id > 0) {
        try {
          $tid = null;
          if ($statusKey) {
            $st = $pdo->prepare('SELECT id FROM task_statuses WHERE `key` = ? LIMIT 1');
            $st->execute([$statusKey]);
            $tid = $st->fetchColumn() ?: null;
          }
          $sql = 'UPDATE tasks SET title = ?, description = ?, category = ?, priority = ?, due_date = ?' . ($tid ? ', task_status_id = ?' : '') . ' WHERE id = ?';
          $params = [$title, $description, $category, $priority, $due_date];
          if ($tid) { $params[] = $tid; }
          $params[] = $task_id;
          $st2 = $pdo->prepare($sql);
          $st2->execute($params);
          $_SESSION['flash_message'] = 'Task updated.';
          header('Location: project_task.php#pane-kanban');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to update task: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Invalid task.';
      }
    } elseif ($action === 'assign_users') {
      $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
      $ids = $_POST['assignee_ids'] ?? [];
      $ids = array_values(array_unique(array_map(function($x){ return (int)$x; }, (array)$ids)));
      $primary_id = $ids[0] ?? null;
      if ($task_id > 0) {
        try {
          $pdo->prepare('DELETE FROM task_assignees WHERE task_id = ?')->execute([$task_id]);
          foreach ($ids as $uid) {
            if ($uid > 0) { $pdo->prepare('INSERT INTO task_assignees (task_id, user_id) VALUES (?, ?)')->execute([$task_id, $uid]); }
          }
          $pdo->prepare('UPDATE tasks SET assignee_id = ? WHERE id = ?')->execute([$primary_id, $task_id]);
          try {
            $stt = $pdo->prepare('SELECT title, project_id FROM tasks WHERE id = ?');
            $stt->execute([$task_id]);
            $row = $stt->fetch();
            $tt = (string)($row['title'] ?? 'Task');
            $sp = $pdo->prepare('SELECT name FROM projects WHERE id = ?');
            $sp->execute([(int)($row['project_id'] ?? 0)]);
            $pn = $sp->fetchColumn();
            foreach ($ids as $uid) {
              if ($uid > 0) {
                $pdo->prepare('INSERT INTO notifications (user_id, title, body, url, is_read) VALUES (?, ?, ?, ?, 0)')
                    ->execute([$uid, 'Assigned to Task: ' . $tt, ($pn ? ('Project: ' . $pn) : ''), 'project_task.php#pane-kanban']);
              }
            }
          } catch (Throwable $_) {}
          $_SESSION['flash_message'] = 'Assignees updated.';
          header('Location: project_task.php#pane-kanban');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to assign users: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Invalid task.';
      }
    } elseif ($action === 'upload_task_asset') {
      $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
      $user_id = $_SESSION['user_id'] ?? null;
      if ($task_id > 0 && $user_id && isset($_FILES['asset_file']) && is_array($_FILES['asset_file'])) {
        $f = $_FILES['asset_file'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($f['size'] ?? 0) > 0) {
          $name = basename($f['name'] ?? ('file_' . time()));
          $mime = (string)($f['type'] ?? 'application/octet-stream');
          $size = (int)($f['size'] ?? 0);
          $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
          $allowed = ['png','jpg','jpeg','gif','webp','svg','pdf'];
          if (!in_array($ext, $allowed, true)) { $form_message = 'Unsupported file type.'; }
          else {
            $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR . $task_id;
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $target = $dir . DIRECTORY_SEPARATOR . (uniqid('att_', true) . '.' . $ext);
            if (@move_uploaded_file($f['tmp_name'], $target)) {
              $relPath = 'uploads/attachments/' . $task_id . '/' . basename($target);
              try {
                $pdo->prepare('INSERT INTO attachments (task_id, file_name, file_path, file_size, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$task_id, $name, $relPath, $size, $mime, $user_id]);
                $_SESSION['flash_message'] = 'Asset uploaded.';
                header('Location: project_task.php#pane-kanban');
                exit();
              } catch (Throwable $e) { $form_message = 'Failed to save asset: ' . $e->getMessage(); }
            } else { $form_message = 'Failed to move uploaded file.'; }
          }
        } else { $form_message = 'No file selected.'; }
      } else { $form_message = 'Invalid task or user.'; }
    } elseif ($action === 'delete_task_asset') {
      $att_id = isset($_POST['attachment_id']) ? (int)$_POST['attachment_id'] : 0;
      if ($att_id > 0) {
        try {
          $pdo->prepare('UPDATE attachments SET deleted_at = NOW() WHERE id = ?')->execute([$att_id]);
          $_SESSION['flash_message'] = 'Asset removed.';
          header('Location: project_task.php#pane-kanban');
          exit();
        } catch (Throwable $e) { $form_message = 'Failed to remove asset: ' . $e->getMessage(); }
      } else { $form_message = 'Invalid asset.'; }
    } elseif ($action === 'trash_task') {
      $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
      if ($task_id > 0) {
        try {
          $pdo->prepare('UPDATE tasks SET deleted_at = NOW() WHERE id = ?')->execute([$task_id]);
          $_SESSION['flash_message'] = 'Task moved to trash.';
          header('Location: project_task.php#pane-kanban');
          exit();
        } catch (Throwable $e) { $form_message = 'Failed to trash task: ' . $e->getMessage(); }
      } else { $form_message = 'Invalid task.'; }
    } elseif ($action === 'delete_task_permanent') {
      $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
      $role = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? ''));
      if ($role !== 'admin') { $form_message = 'Forbidden.'; }
      elseif ($task_id > 0) {
        try {
          $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$task_id]);
          $_SESSION['flash_message'] = 'Task deleted.';
          header('Location: project_task.php#pane-kanban');
          exit();
        } catch (Throwable $e) { $form_message = 'Failed to delete task: ' . $e->getMessage(); }
      } else { $form_message = 'Invalid task.'; }
    } elseif ($action === 'update_user_status') {
      $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
      $new_status = trim(strtolower($_POST['status'] ?? ''));
      $role = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? ''));
      if ($role !== 'admin') { $form_message = 'Forbidden.'; }
      elseif ($user_id > 0 && in_array($new_status, ['active','inactive'], true)) {
        try {
          $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new_status, $user_id]);
          $_SESSION['flash_message'] = 'User ' . $new_status . '.';
          header('Location: project_task.php#pane-projects');
          exit();
        } catch (Throwable $e) { $form_message = 'Failed to update user: ' . $e->getMessage(); }
      } else { $form_message = 'Invalid user or status.'; }
    } 
    elseif ($action === 'assign_team_to_project') {
      $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
      $team_id = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
      if ($project_id > 0) {
        try {
          $stmt = $pdo->prepare('UPDATE projects SET team_id = ? WHERE id = ?');
          $stmt->execute([$team_id ?: null, $project_id]);
          $_SESSION['flash_message'] = 'Team assigned to project.';
          header('Location: project_task.php#pane-projects');
          exit();
        } catch (Throwable $e) {
          $form_message = 'Failed to assign team: ' . $e->getMessage();
        }
      } else {
        $form_message = 'Invalid project.';
      }
    }
  }

  
  $activeProjectsCount = 0;
  $totalTasksCount = 0;
  $completedTasksCount = 0;
  $overdueTasksCount = 0;
  $projects = [];
  $tasksByStatus = ['todo'=>[], 'in-progress'=>[], 'review'=>[], 'done'=>[]];
  $requests = [];

  
  try { $activeProjectsCount = (int)($pdo->query("SELECT COUNT(*) FROM projects p JOIN project_statuses ps ON ps.id = p.project_status_id WHERE ps.`key` IN ('active','at-risk')")->fetchColumn() ?: 0); } catch (Throwable $e) {}
  try { $totalTasksCount = (int)($pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn() ?: 0); } catch (Throwable $e) {}
  try { $completedTasksCount = (int)($pdo->query("SELECT COUNT(*) FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.task_status_id WHERE ts.`key` = 'done'")->fetchColumn() ?: 0); } catch (Throwable $e) {}
  try { $overdueTasksCount = (int)($pdo->query("SELECT COUNT(*) FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.task_status_id WHERE t.due_date IS NOT NULL AND t.due_date < CURDATE() AND (ts.`key` IS NULL OR ts.`key` <> 'done')")->fetchColumn() ?: 0); } catch (Throwable $e) {}

  
  try {
    $projects = $pdo->query("\n      SELECT \n        p.id, p.name, p.description, p.category, p.priority, ps.`key` AS status, p.progress, p.due_date, p.start_date, \n        p.team_id AS team_id, \n        CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS project_manager, \n        tt.name AS team_name, \n        COALESCE(stats.total_tasks,0) AS total_tasks, \n        COALESCE(stats.done_tasks,0) AS done_tasks, \n        COALESCE(stats.overdue_tasks,0) AS overdue_tasks \n      FROM projects p \n      JOIN project_statuses ps ON ps.id = p.project_status_id \n      LEFT JOIN users u ON u.id = p.project_manager_id \n      LEFT JOIN teams tt ON tt.id = p.team_id \n      LEFT JOIN (\n        SELECT t.project_id, \n               COUNT(*) AS total_tasks, \n               SUM(CASE WHEN ts.`key` = 'done' THEN 1 ELSE 0 END) AS done_tasks, \n               SUM(CASE WHEN t.due_date IS NOT NULL AND t.due_date < CURDATE() AND (ts.`key` <> 'done' OR ts.`key` IS NULL) THEN 1 ELSE 0 END) AS overdue_tasks \n        FROM tasks t \n        LEFT JOIN task_statuses ts ON ts.id = t.task_status_id \n        GROUP BY t.project_id\n      ) stats ON stats.project_id = p.id \n      ORDER BY p.id DESC \n      LIMIT 60\n    ")->fetchAll();
  } catch (Throwable $e) { $projects = []; }

  
  try {
    foreach ($pdo->query("SELECT t.id, t.project_id, t.title, t.description, t.category, t.priority, ts.`key` AS status, t.due_date, p.name AS project_name, CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,'')) AS assignee_name, COALESCE(att.asset_count,0) AS asset_count FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.task_status_id LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN users a ON a.id = t.assignee_id LEFT JOIN (SELECT task_id, COUNT(*) AS asset_count FROM attachments WHERE deleted_at IS NULL GROUP BY task_id) att ON att.task_id = t.id WHERE t.deleted_at IS NULL ORDER BY t.id DESC LIMIT 200") as $t) {
      $st = strtolower($t['status'] ?? 'todo');
      if (!isset($tasksByStatus[$st])) $st = 'todo';
      $tasksByStatus[$st][] = [
        'id' => (int)$t['id'],
        'project_id' => (int)($t['project_id'] ?? 0),
        'title' => $t['title'] ?? 'Task',
        'description' => $t['description'] ?? '',
        'category' => $t['category'] ?? '',
        'priority' => $t['priority'] ?? 'medium',
        'due_date' => $t['due_date'] ?? '',
        'project_name' => $t['project_name'] ?? '',
        'assignee' => trim($t['assignee_name'] ?? '') ?: 'Unassigned',
        'asset_count' => (int)($t['asset_count'] ?? 0),
      ];
    }
  } catch (Throwable $e) {}

  
  try {
    foreach ($pdo->query("SELECT er.id, er.task_id, er.current_due_date, er.requested_due_date, er.requested_extension_days, er.reason, er.justification, er.priority, er.status, er.created_at, t.title AS task_title, p.name AS project_name, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS requested_by_name FROM extension_requests er LEFT JOIN tasks t ON t.id = er.task_id LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN users u ON u.id = er.requested_by ORDER BY er.id DESC LIMIT 100") as $r) {
      $name = trim($r['requested_by_name'] ?? '');
      $avatar = $name ? strtoupper(substr($name,0,1)) : 'U';
      $requests[] = [
        'id' => (int)$r['id'],
        'task_title' => $r['task_title'] ?? 'Task',
        'project_name' => $r['project_name'] ?? '',
        'requested_by' => $name ?: 'Unknown',
        'requested_by_avatar' => $avatar,
        'request_date' => $r['created_at'] ?? '',
        'current_due_date' => $r['current_due_date'] ?? '',
        'requested_due_date' => $r['requested_due_date'] ?? '',
        'requested_extension_days' => (int)($r['requested_extension_days'] ?? 0),
        'reason' => $r['reason'] ?? '',
        'justification' => $r['justification'] ?? '',
        'priority' => $r['priority'] ?? 'medium',
        'status' => $r['status'] ?? 'pending',
      ];
    }
  } catch (Throwable $e) {}
}

 if ($pdo && isset($_GET['ajax'])) {
   $aj = $_GET['ajax'] ?? '';
   if ($aj === 'attachments') {
     $task_id = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
     header('Content-Type: application/json');
     if ($task_id > 0) {
       try {
         $st = $pdo->prepare('SELECT id, file_name, file_path, file_size, mime_type FROM attachments WHERE task_id = ? AND (deleted_at IS NULL) ORDER BY id DESC LIMIT 200');
         $st->execute([$task_id]);
         echo json_encode($st->fetchAll());
       } catch (Throwable $e) { echo json_encode([]); }
     } else { echo json_encode([]); }
     exit();
   } elseif ($aj === 'assignees') {
     $task_id = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
     header('Content-Type: application/json');
     if ($task_id > 0) {
       try {
         $st = $pdo->prepare('SELECT u.id, CONCAT(COALESCE(u.first_name,\'\'),\' \',COALESCE(u.last_name,\'\')) AS name FROM task_assignees ta JOIN users u ON u.id = ta.user_id WHERE ta.task_id = ? ORDER BY name ASC');
         $st->execute([$task_id]);
         echo json_encode($st->fetchAll());
       } catch (Throwable $e) { echo json_encode([]); }
     } else { echo json_encode([]); }
     exit();
   }
 }

?>
<?php include 'includes/header.php'; ?>
  <style>
    /* Shared */
    body { background:#f8f9fa; overflow-y: auto !important; }
    .summary-card { border:1px solid #e5e7eb; }
    .muted { color:#6c757d; }

    /* Kanban */
    .kanban-column { border:1px solid #e5e7eb; }
    .kanban-item { border:1px solid #e5e7eb; border-radius:.5rem; padding:.75rem; margin-bottom:.5rem; background:#fff; cursor:pointer; }
    .badge-priority { font-size:.75rem; }
    .kanban-item.dragging { opacity: .6; }
    .kanban-column.drag-over { outline: 2px dashed #0d6efd; }
    #pane-kanban { position: relative; }
    .kanban-loading { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,.7); z-index:10; transition:opacity .2s ease; }
    .kanban-loading.hidden { opacity:0; pointer-events:none; }
    .kanban-body { max-height: calc(100vh - 300px); overflow-y: auto; padding-right: .5rem; }
    .kanban-empty { border:2px dashed #dee2e6; }
    .kanban-meta { font-size:.8125rem; }
    .kanban-badges { display:flex; gap:.25rem; flex-wrap:wrap; }
    .text-blue-600 { color:#0d6efd; }
    .text-purple-600 { color:#6f42c1; }
    .text-green-600 { color:#198754; }
    .text-gray-600 { color:#6c757d; }

    /* Extensions */
    .request-card { border:1px solid #e5e7eb; border-radius:.5rem; background:#fff; }
    .avatar { width:32px; height:32px; border-radius:50%; background:#0d6efd; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:.9rem; }
    .project-modal-body .section-title { font-weight:600; color:#6c757d; display:flex; align-items:center; gap:.5rem; margin:.25rem 0 .25rem; }
    .project-modal-body .form-control, .project-modal-body .form-select { border-radius:.75rem; }
    .project-modal-body .form-floating > .form-control, .project-modal-body .form-floating > textarea.form-control { border-radius:.75rem; }
    .project-modal-body .form-floating label { color:#6c757d; }
    .ui-modal-body .section-title { font-weight:600; color:#6c757d; display:flex; align-items:center; gap:.5rem; margin:.25rem 0 .25rem; }
    .ui-modal-body .form-control, .ui-modal-body .form-select { border-radius:.75rem; }
    .ui-modal-body .form-floating > .form-control, .ui-modal-body .form-floating > textarea.form-control { border-radius:.75rem; }
    .ui-modal-body .form-floating label { color:#6c757d; }
  </style>
  
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="mb-0">Project Workspace</h2>
      <div class="d-flex align-items-center gap-3">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="autoRefreshToggle">
          <label class="form-check-label" for="autoRefreshToggle">Auto Refresh</label>
        </div>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#helpModal"><i class="bi bi-question-circle"></i> Help</button>
        <button class="btn btn-primary" id="btnNewProject" data-bs-toggle="modal" data-bs-target="#newProjectModal"><i class="bi bi-plus-lg"></i> New Project</button>
        <?php $role = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? '')); if ($role === 'admin'): ?>
          <button class="btn btn-outline-danger" id="btnUserControl" data-bs-toggle="modal" data-bs-target="#userControlModal"><i class="bi bi-shield-lock"></i> User Control</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="position-fixed top-0 end-0 p-3" style="z-index: 2000">
      <div id="appToast" class="toast" role="status" aria-live="polite" aria-atomic="true">
        <div class="toast-body">Updated</div>
      </div>
    </div>
    <?php if (!empty($form_message)): ?>
      <div class="alert alert-info"><?= htmlspecialchars($form_message) ?></div>
    <?php endif; ?>

    
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-projects" data-bs-toggle="tab" data-bs-target="#pane-projects" type="button" role="tab">Projects</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-kanban" data-bs-toggle="tab" data-bs-target="#pane-kanban" type="button" role="tab">Kanban</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-extensions" data-bs-toggle="tab" data-bs-target="#pane-extensions" type="button" role="tab">Extensions</button>
      </li>
    </ul>

    <div class="tab-content pt-3">
      
      <div class="tab-pane fade show active" id="pane-projects" role="tabpanel">
        
        <div class="row g-3 mb-4">
          <div class="col-sm-6 col-md-3">
            <div class="card summary-card text-center p-3">
              <div class="text-muted small">Active Projects</div>
              <div class="h4 mb-0" id="activeProjects"><?= htmlspecialchars((string)$activeProjectsCount) ?></div>
            </div>
          </div>
          <div class="col-sm-6 col-md-3">
            <div class="card summary-card text-center p-3">
              <div class="text-muted small">Total Tasks</div>
              <div class="h4 mb-0" id="totalTasks"><?= htmlspecialchars((string)$totalTasksCount) ?></div>
            </div>
          </div>
          <div class="col-sm-6 col-md-3">
            <div class="card summary-card text-center p-3">
              <div class="text-muted small">Completed Tasks</div>
              <div class="h4 mb-0" id="completedTasks"><?= htmlspecialchars((string)$completedTasksCount) ?></div>
            </div>
          </div>
          <div class="col-sm-6 col-md-3">
            <div class="card summary-card text-center p-3">
              <div class="text-muted small">Overdue Tasks</div>
              <div class="h4 mb-0" id="overdueTasks"><?= htmlspecialchars((string)$overdueTasksCount) ?></div>
            </div>
          </div>
        </div>

        
        <div class="row g-2 mb-3">
          <div class="col-12 col-md-4">
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="text" id="projectSearch" class="form-control" placeholder="Search projects...">
            </div>
          </div>
          <div class="col-6 col-md-4">
            <select id="projectStatusFilter" class="form-select">
              <option value="">All Statuses</option>
              <option value="active">Active</option>
              <option value="completed">Completed</option>
              <option value="on-hold">On Hold</option>
              <option value="archived">Archived</option>
              <option value="at-risk">At Risk</option>
            </select>
          </div>
          <div class="col-6 col-md-4">
            <select id="projectPriorityFilter" class="form-select">
              <option value="">All Priorities</option>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>
        </div>

        
        <div class="row" id="projectList">
          <?php foreach ($projects as $p): $pStatus = strtolower($p['status'] ?? 'active'); $pPriority = strtolower($p['priority'] ?? 'medium'); ?>
            <div class="col-12 col-md-6 col-lg-4" data-status="<?= htmlspecialchars($pStatus) ?>" data-priority="<?= htmlspecialchars($pPriority) ?>" data-name="<?= htmlspecialchars($p['name'] ?? '') ?>" data-description="<?= htmlspecialchars($p['description'] ?? '') ?>">
              <div class="card h-100">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <h6 class="card-title mb-0"><?= htmlspecialchars($p['name'] ?? 'Untitled') ?></h6>
                      <small class="text-muted"><?= htmlspecialchars($p['category'] ?? '') ?></small>
                    </div>
                    <?php $prio = strtolower($p['priority'] ?? 'medium'); $badge = ($prio==='critical'?'danger':($prio==='high'?'warning':'secondary')); ?>
                    <div class="text-end">
                      <span class="badge bg-<?= $badge ?><?= $badge==='warning' ? ' text-dark' : '' ?> text-capitalize me-1"><?= htmlspecialchars($p['priority'] ?? 'medium') ?></span>
                      <span class="badge bg-light text-dark text-capitalize"><i class="bi bi-circle-fill me-1" style="font-size:.6rem"></i><?= htmlspecialchars($p['status'] ?? 'active') ?></span>
                      <div class="dropdown d-inline-block ms-1">
                        <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                          <i class="bi bi-three-dots"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                          <li><button class="dropdown-item" data-action="view-kanban" data-projectid="<?= (int)($p['id'] ?? 0) ?>">View Kanban</button></li>
                          <li><button class="dropdown-item" data-action="assign-team" data-projectid="<?= (int)($p['id'] ?? 0) ?>">Assign Team</button></li>
                          <li><button class="dropdown-item" data-action="edit-project" 
                            data-projectid="<?= (int)($p['id'] ?? 0) ?>"
                            data-name="<?= htmlspecialchars($p['name'] ?? '') ?>"
                            data-description="<?= htmlspecialchars($p['description'] ?? '') ?>"
                            data-category="<?= htmlspecialchars($p['category'] ?? '') ?>"
                            data-priority="<?= htmlspecialchars($p['priority'] ?? 'medium') ?>"
                            data-status="<?= htmlspecialchars($p['status'] ?? 'active') ?>"
                            data-start="<?= htmlspecialchars($p['start_date'] ?? '') ?>"
                            data-due="<?= htmlspecialchars($p['due_date'] ?? '') ?>"
                            data-teamid="<?= (int)($p['team_id'] ?? 0) ?>"
                          >Edit Project</button></li>
                          <li><button class="dropdown-item text-danger" data-action="delete-project" data-projectid="<?= (int)($p['id'] ?? 0) ?>">Delete Project</button></li>
                        </ul>
                      </div>
                    </div>
                  </div>
                  <p class="mt-2 mb-2 text-muted"><?= htmlspecialchars($p['description'] ?? '') ?></p>
                  <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Project Manager: <?= htmlspecialchars(trim($p['project_manager'] ?? '') ?: 'Unassigned') ?></small>
                    <small class="text-muted">Team: <?= htmlspecialchars(trim($p['team_name'] ?? '') ?: 'Unassigned') ?></small>
                    <small class="text-muted">Due: <?= htmlspecialchars($p['due_date'] ?? '') ?></small>
                  </div>
                  <div class="d-flex gap-2 mt-2">
                    <span class="badge bg-secondary">Tasks: <?= (int)($p['total_tasks'] ?? 0) ?></span>
                    <span class="badge bg-success">Done: <?= (int)($p['done_tasks'] ?? 0) ?></span>
                    <span class="badge bg-danger">Overdue: <?= (int)($p['overdue_tasks'] ?? 0) ?></span>
                  </div>
                  <div class="progress mt-2" style="height:8px"><div class="progress-bar" style="width:<?= (int)($p['progress'] ?? 0) ?>%"></div></div>
                  <button class="btn btn-outline-primary w-100 mt-2" data-action="view-kanban" data-projectid="<?= (int)($p['id'] ?? 0) ?>">View Tasks in Kanban</button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($projects)): ?>
            <div class="col-12"><div class="alert alert-light border">No projects yet.</div></div>
          <?php endif; ?>
        </div>
      </div>

      
      <div class="tab-pane fade" id="pane-kanban" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-0">Kanban Board</h5>
            <div class="text-muted small">Drag and drop tasks or use the actions menu to update status</div>
          </div>
          <button id="btnAddTask" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTaskModal"><i class="bi bi-plus-lg"></i> New Task</button>
        </div>
        <div class="row gx-3" id="kanbanBoard">
          <?php
          $columns = [ 'todo'=>'To Do', 'in-progress'=>'In Progress', 'review'=>'Review', 'done'=>'Done' ];
          foreach ($columns as $key => $label):
            $count = count($tasksByStatus[$key] ?? []);
            $icon = $key==='todo' ? 'bi-circle' : ($key==='in-progress' ? 'bi-play-circle' : ($key==='review' ? 'bi-eye' : 'bi-check-circle'));
            $color = $key==='todo' ? 'text-gray-600' : ($key==='in-progress' ? 'text-blue-600' : ($key==='review' ? 'text-purple-600' : 'text-green-600'));
          ?>
            <div class="col-12 col-md-6 col-lg-3 mb-3">
              <div class="kanban-column card" data-status="<?= $key ?>">
                <div class="card-header bg-white">
                  <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                      <i class="bi <?= $icon ?> <?= $color ?>"></i>
                      <div class="fw-semibold small mb-0"><?= $label ?></div>
                    </div>
                    <span class="badge bg-secondary"><?= (int)$count ?></span>
                  </div>
                </div>
                <div class="card-body kanban-body" id="col-<?= $key ?>">
                  <?php foreach (($tasksByStatus[$key] ?? []) as $t):
                    $prio = strtolower($t['priority'] ?? 'medium');
                    $badge = ($prio==='critical'?'danger':($prio==='high'?'warning':'secondary'));
                    $dueStr = trim($t['due_date'] ?? '');
                    $isOverdue = false;
                    if ($dueStr && $key !== 'done') {
                      try { $isOverdue = (new DateTime($dueStr) < new DateTime()); } catch (Throwable $e) { $isOverdue = false; }
                    }
                  ?>
                    <div class="kanban-item" data-id="<?= (int)$t['id'] ?>" data-projectid="<?= (int)$t['project_id'] ?>" data-title="<?= htmlspecialchars($t['title']) ?>" data-description="<?= htmlspecialchars($t['description']) ?>" data-projectname="<?= htmlspecialchars($t['project_name']) ?>" data-assignee="<?= htmlspecialchars($t['assignee']) ?>" data-due="<?= htmlspecialchars($t['due_date']) ?>" data-priority="<?= htmlspecialchars($t['priority']) ?>" data-category="<?= htmlspecialchars($t['category']) ?>" data-status="<?= htmlspecialchars($key) ?>">
                      <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="flex-grow-1">
                          <div class="fw-medium small mb-1"><?= htmlspecialchars($t['title']) ?></div>
                          <div class="text-muted small"><?= htmlspecialchars($t['project_name']) ?></div>
                        </div>
                        <div class="dropdown">
                          <span class="badge bg-<?= $badge ?><?= $badge==='warning' ? ' text-dark' : '' ?> badge-priority text-capitalize me-2"><?= htmlspecialchars($t['priority']) ?></span>
                          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Actions</button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li><button class="dropdown-item" data-action="view-task-details" data-taskid="<?= (int)$t['id'] ?>">View Details</button></li>
                            <li><button class="dropdown-item" data-action="edit-task" data-taskid="<?= (int)$t['id'] ?>">Edit Task</button></li>
                            <li><button class="dropdown-item" data-action="comment" data-taskid="<?= (int)$t['id'] ?>">Add Comment</button></li>
                            <li><button class="dropdown-item" data-action="assign-users" data-taskid="<?= (int)$t['id'] ?>">Assign Users</button></li>
                            <li><button class="dropdown-item" data-action="upload-asset" data-taskid="<?= (int)$t['id'] ?>">Upload Asset</button></li>
                            <li><button class="dropdown-item" data-action="view-assets" data-taskid="<?= (int)$t['id'] ?>">View Assets</button></li>
                            <li><button class="dropdown-item" data-action="add-subtask" data-taskid="<?= (int)$t['id'] ?>">Add Subtask</button></li>
                            <li><button class="dropdown-item" data-action="time" data-taskid="<?= (int)$t['id'] ?>">Log Time</button></li>
                            <li><button class="dropdown-item" data-action="block" data-taskid="<?= (int)$t['id'] ?>">Mark as Blocked</button></li>
                            <li><button class="dropdown-item text-warning" data-action="trash-task" data-taskid="<?= (int)$t['id'] ?>">Move to Trash</button></li>
                            <?php $role = strtolower($_SESSION['role'] ?? ($_SESSION['login_user']['role'] ?? '')); if ($role === 'admin'): ?>
                              <li><button class="dropdown-item text-danger" data-action="delete-task" data-taskid="<?= (int)$t['id'] ?>">Delete Permanently</button></li>
                            <?php endif; ?>
                            <?php if ($isOverdue): ?>
                              <li><hr class="dropdown-divider"></li>
                              <li><button class="dropdown-item text-warning" data-action="request-extension" data-taskid="<?= (int)$t['id'] ?>">Request Extension</button></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <?php if ($key !== 'todo'): ?><li><button class="dropdown-item" data-action="move" data-status="todo" data-taskid="<?= (int)$t['id'] ?>">Move to To Do</button></li><?php endif; ?>
                            <?php if ($key !== 'in-progress'): ?><li><button class="dropdown-item" data-action="move" data-status="in-progress" data-taskid="<?= (int)$t['id'] ?>">Move to In Progress</button></li><?php endif; ?>
                            <?php if ($key !== 'review'): ?><li><button class="dropdown-item" data-action="move" data-status="review" data-taskid="<?= (int)$t['id'] ?>">Move to Review</button></li><?php endif; ?>
                            <?php if ($key !== 'done'): ?><li><button class="dropdown-item" data-action="move" data-status="done" data-taskid="<?= (int)$t['id'] ?>">Move to Done</button></li><?php endif; ?>
                          </ul>
                        </div>
                      </div>
                      <div class="kanban-meta">
                        <div class="kanban-badges mb-2">
                          <?php if (!empty($t['category'])): ?><span class="badge bg-light text-dark"><?= htmlspecialchars($t['category']) ?></span><?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-between">
                          <div class="text-muted small d-flex align-items-center gap-2"><i class="bi bi-person"></i><span class="truncate"><?= htmlspecialchars($t['assignee']) ?></span></div>
                          <div class="small d-flex align-items-center gap-2 <?= $isOverdue ? 'text-danger' : 'text-muted' ?>"><i class="bi bi-paperclip"></i><span><?= (int)($t['asset_count'] ?? 0) ?></span><i class="bi bi-calendar"></i><span><?= htmlspecialchars($t['due_date']) ?></span><?php if ($isOverdue): ?><i class="bi bi-exclamation-circle-fill"></i><?php endif; ?></div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                  <?php if (empty($tasksByStatus[$key])): ?>
                    <div class="card kanban-empty">
                      <div class="card-body p-4 text-center">
                        <i class="bi <?= $icon ?> <?= $color ?> opacity-50" style="font-size:1.5rem"></i>
                        <div class="text-muted small mt-2">No tasks in <?= strtolower($label) ?></div>
                        <div class="text-muted small">Drag tasks here</div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      
      <div class="tab-pane fade" id="pane-extensions" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0">Extension Request Management</h5>
          <button class="btn btn-primary" id="btnNewRequest" data-bs-toggle="modal" data-bs-target="#newRequestModal"><i class="bi bi-plus-circle"></i> New Request</button>
        </div>
        <div class="mb-3">
          <button class="btn btn-outline-secondary me-2 filter-btn active" data-status="all">All</button>
          <button class="btn btn-outline-secondary me-2 filter-btn" data-status="pending">Pending</button>
          <button class="btn btn-outline-secondary me-2 filter-btn" data-status="approved">Approved</button>
          <button class="btn btn-outline-secondary filter-btn" data-status="rejected">Rejected</button>
        </div>
        <div id="requestsContainer" class="row g-3">
          <?php foreach ($requests as $r): $prio = strtolower($r['priority'] ?? 'medium'); $badge = ($prio==='critical'?'danger':($prio==='high'?'warning':'secondary')); $rStatus = strtolower($r['status'] ?? 'pending'); ?>
            <div class="col-12 col-md-6" data-status="<?= htmlspecialchars($rStatus) ?>">
              <div class="request-card p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($r['task_title']) ?></div>
                    <small class="text-muted"><?= htmlspecialchars($r['project_name']) ?></small>
                  </div>
                  <div class="text-end">
                    <span class="badge bg-<?= $badge ?><?= $badge==='warning' ? ' text-dark' : '' ?> text-capitalize me-1"><?= htmlspecialchars($r['priority']) ?></span>
                    <span class="badge bg-light text-dark text-capitalize"><i class="bi bi-circle-fill me-1" style="font-size:.6rem"></i><?= htmlspecialchars($r['status']) ?></span>
                  </div>
                </div>
                <div class="d-flex align-items-center gap-2 mt-2">
                  <span class="avatar"><?= htmlspecialchars($r['requested_by_avatar']) ?></span>
                  <small class="text-muted"><?= htmlspecialchars($r['requested_by']) ?> • <?= htmlspecialchars($r['request_date']) ?></small>
                </div>
                <div class="mt-2 small">Reason: <span class="fw-semibold"><?= htmlspecialchars($r['reason']) ?></span></div>
                <div class="mt-1 small text-muted">Current Due: <?= htmlspecialchars($r['current_due_date']) ?> → Requested: <?= htmlspecialchars($r['requested_due_date']) ?> (+<?= (int)$r['requested_extension_days'] ?>d)</div>
                <div class="mt-2 small">Justification: <?= htmlspecialchars($r['justification']) ?></div>
                <div class="mt-3 d-flex gap-2">
                  <button class="btn btn-sm btn-outline-success" data-action="extension-status" data-status="approved" data-requestid="<?= (int)$r['id'] ?>">Approve</button>
                  <button class="btn btn-sm btn-outline-danger" data-action="extension-status" data-status="rejected" data-requestid="<?= (int)$r['id'] ?>">Reject</button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($requests)): ?>
            <div class="col-12"><div class="alert alert-light border">No extension requests.</div></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  
  <div class="modal fade" id="newProjectModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <form id="newProjectForm" class="modal-content" method="POST" action="project_task.php">
        <div class="modal-header">
          <div>
            <h5 class="modal-title mb-0">Create New Project</h5>
            <div class="text-muted small">Fill in the project details to create a new project</div>
          </div>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body project-modal-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="form-floating">
                <input type="text" id="projName" name="name" class="form-control" placeholder="Project Name" required>
                <label for="projName">Project Name *</label>
              </div>
            </div>
            <div class="col-12">
              <div class="form-floating">
                <textarea id="projDesc" name="description" class="form-control" placeholder="Description" style="height:130px" required></textarea>
                <label for="projDesc">Description *</label>
              </div>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Category</label>
              <input type="text" id="projCategory" name="category" class="form-control" placeholder="e.g., Full Stack Development">
            </div>
            <div class="col-md-6">
              <label class="form-label">Priority <span class="text-danger">*</span></label>
              <select id="projPriority" name="priority" class="form-select" required>
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </select>
            </div>
          </div>
          <div class="row g-3 mt-0">
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select id="projStatus" name="status" class="form-select">
                <option value="planning" selected>Planning</option>
                <option value="active">Active</option>
                <option value="on-hold">On Hold</option>
                <option value="completed">Completed</option>
                <option value="archived">Archived</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Team</label>
              <select id="projTeamSelect" name="team_id" class="form-select" aria-label="Team">
                <?php
                  try {
                    $teams = $pdo->query("SELECT id, name FROM teams ORDER BY name ASC LIMIT 300")->fetchAll();
                    echo '<option value="">Select Team</option>';
                    foreach ($teams as $tm) {
                      echo '<option value="'.(int)$tm['id'].'">'.htmlspecialchars($tm['name']).'</option>';
                    }
                  } catch (Throwable $e) {
                    echo '<option value="">No teams available</option>';
                  }
                ?>
              </select>
            </div>
          </div>
          <div class="row g-3 mt-0">
            <div class="col-md-6">
              <label class="form-label">Start Date</label>
              <input type="date" id="projStart" name="start_date" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Due Date <span class="text-danger">*</span></label>
              <input type="date" id="projDue" name="due_date" class="form-control" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="project_id" id="projId">
          <input type="hidden" name="action" value="create_project">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" type="submit">Create Project</button>
        </div>
      </form>
    </div>
  </div>

  
  <div class="modal fade" id="taskModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 id="taskModalTitle" class="modal-title">Task Details</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ui-modal-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="section-title"><i class="bi bi-card-text"></i><span>Details</span></div>
              <div class="small text-muted mb-1">Description</div>
              <div id="taskDescription" class="border rounded p-2 bg-light"></div>
            </div>
            <div class="col-12 col-md-6">
              <div class="small text-muted mb-1">Project</div>
              <div id="taskProject" class="border rounded p-2 bg-light"></div>
            </div>
            <div class="col-12 col-md-6">
              <div class="small text-muted mb-1">Assignee</div>
              <div id="taskAssignee" class="border rounded p-2 bg-light"></div>
            </div>
            <div class="col-12 col-md-6">
              <div class="small text-muted mb-1">Due Date</div>
              <div id="taskDue" class="border rounded p-2 bg-light"></div>
            </div>
            <div class="col-12 col-md-3">
              <div class="small text-muted mb-1">Priority</div>
              <div id="taskPriority" class="border rounded p-2 bg-light"></div>
            </div>
            <div class="col-12 col-md-3">
              <div class="small text-muted mb-1">Category</div>
              <div id="taskCategory" class="border rounded p-2 bg-light"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editTaskModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="editTaskForm" class="modal-content" method="POST" action="project_task.php">
        <div class="modal-header">
          <h5 class="modal-title">Edit Task</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ui-modal-body">
          <input type="hidden" name="task_id" id="editTaskId">
          <div class="row g-3">
            <div class="col-12">
              <div class="form-floating"><input name="title" id="editTaskTitle" class="form-control" placeholder="Title" required><label for="editTaskTitle">Title</label></div>
            </div>
            <div class="col-12">
              <div class="form-floating"><textarea name="description" id="editTaskDesc" class="form-control" placeholder="Description" style="height:120px"></textarea><label for="editTaskDesc">Description</label></div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-floating"><input name="category" id="editTaskCategory" class="form-control" placeholder="Category"><label for="editTaskCategory">Category</label></div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Due Date</label>
              <input type="date" name="due_date" id="editTaskDue" class="form-control">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Priority</label>
              <select name="priority" id="editTaskPriority" class="form-select"><option value="low">Low</option><option value="normal">Normal</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option></select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Status</label>
              <select name="status" id="editTaskStatus" class="form-select"><option value="todo">To Do</option><option value="in-progress">In Progress</option><option value="review">In Review</option><option value="completed">Completed</option></select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="action" value="update_task">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal fade" id="assignUsersModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="assignUsersForm" class="modal-content" method="POST" action="project_task.php">
        <div class="modal-header">
          <h5 class="modal-title">Assign Users</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ui-modal-body">
          <input type="hidden" name="task_id" id="assignTaskId">
          <div class="mb-2"><label class="form-label">Assignees</label>
            <select id="assignUsersSelect" name="assignee_ids[]" class="form-select" multiple>
              <?php
                try {
                  $userRows = $pdo->query("SELECT id, first_name, last_name, username FROM users ORDER BY first_name ASC, last_name ASC LIMIT 500")->fetchAll();
                  foreach ($userRows as $u) {
                    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? ('User '.$u['id']));
                    echo '<option value="'.(int)$u['id'].'">'.htmlspecialchars($name).'</option>';
                  }
                } catch (Throwable $e) { }
              ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="action" value="assign_users">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Assign</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal fade" id="assignTeamModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="assignTeamForm" class="modal-content" method="POST" action="project_task.php">
        <div class="modal-header"><h5 class="modal-title">Assign Team to Project</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body ui-modal-body">
          <input type="hidden" name="project_id" id="assignTeamProjectId">
          <div class="mb-2"><label class="form-label">Team</label>
            <select id="assignTeamSelect" name="team_id" class="form-select" required>
              <?php
                try {
                  $teams = $pdo->query("SELECT id, name FROM teams ORDER BY name ASC LIMIT 300")->fetchAll();
                  echo '<option value="">Select Team</option>';
                  foreach ($teams as $tm) {
                    echo '<option value="'.(int)$tm['id'].'">'.htmlspecialchars($tm['name']).'</option>';
                  }
                } catch (Throwable $e) { echo '<option value="">No teams available</option>'; }
              ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="action" value="assign_team_to_project">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Assign</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal fade" id="uploadAssetModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="uploadAssetForm" class="modal-content" method="POST" enctype="multipart/form-data" action="project_task.php">
        <div class="modal-header">
          <h5 class="modal-title">Upload Asset</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ui-modal-body">
          <input type="hidden" name="task_id" id="uploadAssetTaskId">
          <div class="mb-2"><label class="form-label">File</label><input type="file" name="asset_file" id="assetFileInput" class="form-control" required></div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="action" value="upload_task_asset">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Upload</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal fade" id="assetsListModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Task Assets</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="assetsListBody"></div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="userControlModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">User Account Control</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <div class="list-group" id="userControlList">
              <?php
                try {
                  $rows = $pdo->query('SELECT id, first_name, last_name, email, status FROM users ORDER BY first_name ASC LIMIT 200')->fetchAll();
                  foreach ($rows as $u) {
                    $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')) ?: 'User';
                    $st = strtolower($u['status'] ?? 'active');
                    echo '<div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-medium">'.htmlspecialchars($nm).'</div><small class="text-muted">'.htmlspecialchars($u['email'] ?? '').'</small></div><div class="btn-group"><form method="POST" action="project_task.php" class="d-inline"><input type="hidden" name="action" value="update_user_status"><input type="hidden" name="user_id" value="'.(int)$u['id'].'"><input type="hidden" name="status" value="'.($st==='active'?'inactive':'active').'"><button class="btn btn-sm '.($st==='active'?'btn-outline-danger':'btn-outline-success').'">'.($st==='active'?'Disable':'Activate').'</button></form></div></div>';
                  }
                } catch (Throwable $e) { }
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <div class="modal fade" id="commentModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="commentForm" class="modal-content" method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Add Comment</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ui-modal-body">
          <input type="hidden" name="task_id" id="commentTaskId">
          <div class="form-floating">
            <textarea id="commentText" name="comment_text" class="form-control" placeholder="Comment" style="height:140px" required></textarea>
            <label for="commentText">Comment</label>
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="action" value="add_comment">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Add Comment</button>
        </div>
      </form>
    </div>
  </div>

  
  <div class="modal fade" id="timeLogModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="timeLogForm" class="modal-content" method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Log Time</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ui-modal-body">
          <input type="hidden" name="task_id" id="timeLogTaskId">
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <div class="form-floating">
                <input type="number" step="0.25" min="0" id="timeLogHours" name="hours" class="form-control" placeholder="Hours" required>
                <label for="timeLogHours">Hours</label>
              </div>
            </div>
            <div class="col-12 col-md-8">
              <div class="form-floating">
                <textarea id="timeLogNotes" name="notes" class="form-control" placeholder="Notes" style="height:100px"></textarea>
                <label for="timeLogNotes">Notes (optional)</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="action" value="log_time">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Log Time</button>
        </div>
      </form>
    </div>
  </div>

  
  <div class="modal fade" id="blockModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="blockForm" class="modal-content" method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Mark Task as Blocked</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ui-modal-body">
          <input type="hidden" name="task_id" id="blockTaskId">
          <div class="form-floating">
            <textarea id="blockReason" name="reason" class="form-control" placeholder="Reason" style="height:120px" required></textarea>
            <label for="blockReason">Reason</label>
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="action" value="mark_blocked">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Mark Blocked</button>
        </div>
      </form>
    </div>
  </div>

  
  <div class="modal fade" id="newTaskModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="newTaskForm" class="modal-content" method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Add New Task</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ui-modal-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="form-floating">
                <input id="taskTitleInput" name="title" class="form-control" placeholder="Title" required>
                <label for="taskTitleInput">Title</label>
              </div>
            </div>
            <div class="col-12">
              <div class="form-floating">
                <textarea id="taskDescriptionInput" name="description" class="form-control" placeholder="Description" style="height:120px"></textarea>
                <label for="taskDescriptionInput">Description (optional)</label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Project</label>
              <select id="taskProjectSelect" name="project_id" class="form-select" required>
              <?php foreach ($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name'] ?? 'Untitled') ?></option>
              <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-floating">
                <input id="taskCategoryInput" name="category" class="form-control" placeholder="Category">
                <label for="taskCategoryInput">Category</label>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Assignee</label>
              <select id="taskAssigneeInput" name="assignee_id" class="form-select">
              <option value="">Unassigned</option>
              <?php
                try {
                  $userRows = $pdo->query("SELECT id, first_name, last_name, username FROM users ORDER BY first_name ASC, last_name ASC LIMIT 500")->fetchAll();
                  foreach ($userRows as $u) {
                    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? ('User '.$u['id']));
                    echo '<option value="'.(int)$u['id'].'">'.htmlspecialchars($name).'</option>';
                  }
                } catch (Throwable $e) {  }
              ?>
              </select>
            </div>
            
            <div class="col-12 col-md-6">
              <label class="form-label">Due Date</label>
              <input id="taskDueInput" name="due_date" class="form-control" type="date">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Priority</label>
              <select id="taskPrioritySelect" name="priority" class="form-select">
              <option value="low">Low</option>
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
              </select>
            </div>
          </div>
        </div>
      <div class="modal-footer">
        <input type="hidden" name="status" value="todo">
        <input type="hidden" name="action" value="create_task">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Create Task</button>
      </div>
      </form>
    </div>
  </div>

  
  <div class="modal fade" id="subtaskModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="newSubtaskForm" class="modal-content" method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Add Subtask</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ui-modal-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="form-floating">
                <input id="subtaskTitleInput" name="title" class="form-control" placeholder="Title" required>
                <label for="subtaskTitleInput">Title</label>
              </div>
            </div>
            <div class="col-12">
              <div class="form-floating">
                <textarea id="subtaskDescriptionInput" name="description" class="form-control" placeholder="Description" style="height:120px"></textarea>
                <label for="subtaskDescriptionInput">Description (optional)</label>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-floating">
                <input id="subtaskCategoryInput" name="category" class="form-control" placeholder="Category">
                <label for="subtaskCategoryInput">Category</label>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Assignee</label>
              <select id="subtaskAssigneeInput" name="assignee_id" class="form-select">
              <option value="">Unassigned</option>
              <?php
                try {
                  $userRows = $pdo->query("SELECT id, first_name, last_name, username FROM users ORDER BY first_name ASC, last_name ASC LIMIT 500")->fetchAll();
                  foreach ($userRows as $u) {
                    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? ('User '.$u['id']));
                    echo '<option value="'.(int)$u['id'].'">'.htmlspecialchars($name).'</option>';
                  }
                } catch (Throwable $e) { }
              ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Due Date</label>
              <input id="subtaskDueInput" name="due_date" class="form-control" type="date">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Priority</label>
              <select id="subtaskPrioritySelect" name="priority" class="form-select">
              <option value="low">Low</option>
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="project_id" id="subtaskProjectId">
          <input type="hidden" name="parent_task_id" id="subtaskParentId">
          <input type="hidden" name="status" value="todo">
          <input type="hidden" name="action" value="create_task">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Create Subtask</button>
        </div>
      </form>
    </div>
  </div>

  
  <div class="modal fade" id="newRequestModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <form id="newRequestForm" class="modal-content" method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Submit Extension Request</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body ui-modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="form-floating">
                <input type="text" id="reqTaskTitle" name="task_title" class="form-control" placeholder="Task Title" required>
                <label for="reqTaskTitle">Task Title</label>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-floating">
                <input type="text" id="reqProject" name="project_name" class="form-control" placeholder="Project Name">
                <label for="reqProject">Project Name</label>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Current Due Date</label>
              <input type="date" id="reqCurrentDue" name="current_due_date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Requested New Due Date</label>
              <input type="date" id="reqNewDue" name="requested_due_date" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Reason</label>
              <select id="reqReason" name="reason" class="form-select" required>
              <option value="">Select...</option>
              <option>Technical Complexity</option>
              <option>Scope Change</option>
              <option>Resource Constraints</option>
              <option>Dependencies</option>
              <option>External Factors</option>
              <option>Unforeseen Issues</option>
              </select>
            </div>
            <div class="col-12">
              <div class="form-floating">
                <textarea id="reqJustification" name="justification" class="form-control" placeholder="Justification" style="height:140px" required></textarea>
                <label for="reqJustification">Justification</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <input type="hidden" name="priority" value="medium">
          <input type="hidden" name="action" value="create_extension_request">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" type="submit">Submit Request</button>
        </div>
      </form>
    </div>
  </div>

  
  <div class="modal fade" id="helpModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Workspace Help</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <ul>
            <li>Manage projects in the Projects tab.</li>
            <li>Track tasks by status in Kanban.</li>
            <li>Submit and review extension requests in Extensions.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
  
  <script>
    // Projects client-side filtering to mirror React behavior
    (function(){
      const searchEl = document.getElementById('projectSearch');
      const statusEl = document.getElementById('projectStatusFilter');
      const prioEl = document.getElementById('projectPriorityFilter');
      const cards = Array.from(document.querySelectorAll('#projectList > div[col-12], #projectList > div'));
      function matches(card){
        const name = (card.getAttribute('data-name') || '').toLowerCase();
        const desc = (card.getAttribute('data-description') || '').toLowerCase();
        const status = (card.getAttribute('data-status') || '').toLowerCase();
        const prio = (card.getAttribute('data-priority') || '').toLowerCase();
        const q = (searchEl?.value || '').toLowerCase();
        const statusFilter = statusEl?.value || '';
        const prioFilter = prioEl?.value || '';
        const matchSearch = !q || name.includes(q) || desc.includes(q);
        const matchStatus = !statusFilter || status === statusFilter;
        const matchPrio = !prioFilter || prio === prioFilter;
        return matchSearch && matchStatus && matchPrio;
      }
      function apply(){
        let visibleCount = 0;
        cards.forEach(card => {
          const show = matches(card);
          card.style.display = show ? '' : 'none';
          if (show) visibleCount++;
        });
        // Show empty state if none match
        const emptyAlert = document.querySelector('#projectList .alert');
        if (emptyAlert) emptyAlert.parentElement.style.display = visibleCount === 0 ? '' : 'none';
      }
      ['input','change'].forEach(evt => {
        searchEl?.addEventListener(evt, apply);
        statusEl?.addEventListener(evt, apply);
        prioEl?.addEventListener(evt, apply);
      });
      apply();
    })();

    // Optional auto-refresh for real-time feel; reloads page every 15s when enabled.
    (function(){
      const key = 'pms_auto_refresh';
      const toggle = document.getElementById('autoRefreshToggle');
      const intervalMs = 15000;
      let handle = null;

      function start(){
        if (handle) return;
        handle = setInterval(function(){
          if (document.visibilityState === 'visible') { location.reload(); }
        }, intervalMs);
      }
      function stop(){ if (handle) { clearInterval(handle); handle = null; } }
      function initState(){
        const on = localStorage.getItem(key) === '1';
        if (toggle) toggle.checked = on;
        if (on) start(); else stop();
      }
      if (toggle) {
        toggle.addEventListener('change', function(){
          const on = toggle.checked; localStorage.setItem(key, on ? '1' : '0');
          if (on) start(); else stop();
        });
      }
      initState();
    })();

    // Kanban actions: move status, open modals
    (function(){
      const statusForm = document.getElementById('taskStatusForm');
      const taskIdInput = document.getElementById('taskStatusTaskId');
      const statusInput = document.getElementById('taskStatusNew');
      let kanbanProjectFilter = null;
      function applyKanbanFilter(){
        const items = Array.from(document.querySelectorAll('.kanban-item'));
        let visibleCount = 0;
        items.forEach(item => {
          const pid = item.getAttribute('data-projectid') || '';
          const show = !kanbanProjectFilter || String(pid) === String(kanbanProjectFilter);
          item.style.display = show ? '' : 'none';
          if (show) visibleCount++;
        });
        // Optionally hide empty state text in columns
      }
      function priorityBadge(prio){
        const p = String(prio || 'medium').toLowerCase();
        return p === 'critical' ? 'danger' : (p === 'high' ? 'warning' : 'secondary');
      }
      function renderItems(container, items, status){
        const frag = document.createDocumentFragment();
        if (!items || items.length === 0) {
          const empty = document.createElement('div');
          empty.className = 'text-muted small';
          empty.textContent = 'No tasks';
          frag.appendChild(empty);
        } else {
          items.forEach(t => {
            const item = document.createElement('div');
            item.className = 'kanban-item';
            item.setAttribute('data-id', String(t.id));
            item.setAttribute('data-projectid', String(t.project_id));
            item.setAttribute('data-title', t.title || 'Task');
            item.setAttribute('data-description', t.description || '');
            item.setAttribute('data-projectname', t.project_name || '');
            item.setAttribute('data-assignee', t.assignee || 'Unassigned');
            item.setAttribute('data-due', t.due_date || '');
            item.setAttribute('data-priority', t.priority || 'medium');
            item.setAttribute('data-category', t.category || '');
            item.setAttribute('data-status', status);
            const badge = priorityBadge(t.priority);
            const header = document.createElement('div');
            header.className = 'd-flex justify-content-between align-items-start';
            const left = document.createElement('div');
            const titleEl = document.createElement('div');
            titleEl.className = 'fw-semibold';
            titleEl.textContent = t.title || 'Task';
            const projEl = document.createElement('small');
            projEl.className = 'text-muted';
            projEl.textContent = t.project_name || '';
            left.appendChild(titleEl);
            left.appendChild(projEl);
            const dropdown = document.createElement('div');
            dropdown.className = 'dropdown';
            const prioEl = document.createElement('span');
            prioEl.className = 'badge bg-' + badge + (badge === 'warning' ? ' text-dark' : '') + ' badge-priority text-capitalize me-2';
            prioEl.textContent = (t.priority || 'medium');
            const btn = document.createElement('button');
            btn.className = 'btn btn-sm btn-outline-secondary dropdown-toggle';
            btn.type = 'button';
            btn.setAttribute('data-bs-toggle','dropdown');
            btn.setAttribute('aria-expanded','false');
            btn.textContent = 'Actions';
            const menu = document.createElement('ul');
            menu.className = 'dropdown-menu dropdown-menu-end';
            ['todo','in-progress','review','done'].forEach(s => {
              const li = document.createElement('li');
              const b = document.createElement('button');
              b.className = 'dropdown-item';
              b.setAttribute('data-action','move');
              b.setAttribute('data-status', s);
              b.setAttribute('data-taskid', String(t.id));
              b.textContent = 'Move to ' + (s === 'todo' ? 'To Do' : (s === 'in-progress' ? 'In Progress' : (s === 'review' ? 'Review' : 'Done')));
              li.appendChild(b);
              menu.appendChild(li);
            });
            const divider = document.createElement('li');
            divider.innerHTML = '<hr class="dropdown-divider">';
            menu.appendChild(divider);
          const actions = [
            {a:'view-task-details', t:'View Details'},
            {a:'comment', t:'Add Comment'},
            {a:'add-subtask', t:'Add Subtask'},
            {a:'time', t:'Log Time'},
            {a:'block', t:'Mark as Blocked'}
          ];
            actions.forEach(act => {
              const li = document.createElement('li');
              const b = document.createElement('button');
              b.className = 'dropdown-item';
              b.setAttribute('data-action', act.a);
              b.setAttribute('data-taskid', String(t.id));
              b.textContent = act.t;
              li.appendChild(b);
              menu.appendChild(li);
            });
            dropdown.appendChild(prioEl);
            dropdown.appendChild(btn);
            dropdown.appendChild(menu);
            header.appendChild(left);
            header.appendChild(dropdown);
            const meta = document.createElement('div');
            meta.className = 'd-flex justify-content-between mt-2';
            const ass = document.createElement('small');
            ass.className = 'text-muted';
            ass.textContent = 'Assignee: ' + (t.assignee || 'Unassigned');
            const due = document.createElement('small');
            due.className = 'text-muted';
            due.textContent = 'Due: ' + (t.due_date || '');
            meta.appendChild(ass);
            meta.appendChild(due);
            item.appendChild(header);
            item.appendChild(meta);
            frag.appendChild(item);
          });
        }
        container.innerHTML = '';
        container.appendChild(frag);
      }
      
      function initDnD(){
        const items = document.querySelectorAll('.kanban-item');
        const columns = document.querySelectorAll('.kanban-column');
        items.forEach(item => {
          item.setAttribute('draggable','true');
          item.addEventListener('dragstart', e => {
            item.classList.add('dragging');
            const id = item.getAttribute('data-id') || '';
            e.dataTransfer?.setData('text/plain', id);
          });
          item.addEventListener('dragend', () => { item.classList.remove('dragging'); });
        });
        columns.forEach(col => {
          if (col.getAttribute('data-dnd-wired') === '1') return;
          col.setAttribute('data-dnd-wired','1');
          col.addEventListener('dragover', e => { e.preventDefault(); col.classList.add('drag-over'); });
          col.addEventListener('dragleave', () => { col.classList.remove('drag-over'); });
          col.addEventListener('drop', e => {
            e.preventDefault();
            col.classList.remove('drag-over');
            const id = e.dataTransfer?.getData('text/plain');
            const newStatus = col.getAttribute('data-status') || '';
            if (id && newStatus && statusForm && taskIdInput && statusInput) {
              taskIdInput.value = id;
              statusInput.value = newStatus;
              statusForm.submit();
            }
          });
        });
      }
      
      document.addEventListener('click', function(e){
        const target = e.target;
        if (!(target instanceof HTMLElement)) return;
        const action = target.getAttribute('data-action');
        if (!action) return;
        const taskId = target.getAttribute('data-taskid');
        if (action === 'move' && statusForm && taskIdInput && statusInput) {
          const st = target.getAttribute('data-status');
          taskIdInput.value = taskId || '';
          statusInput.value = st || '';
          statusForm.submit();
        } else if (action === 'view-kanban') {
          const projId = target.getAttribute('data-projectid');
          kanbanProjectFilter = projId || null;
          const tabBtn = document.getElementById('tab-kanban');
          if (tabBtn) {
            tabBtn.click();
            setTimeout(() => {
              applyKanbanFilter();
              const pane = document.getElementById('pane-kanban');
              pane?.scrollIntoView({behavior:'smooth'});
            }, 50);
          } else {
            applyKanbanFilter();
          }
        } else if (action === 'view-task-details') {
          const item = target.closest('.kanban-item');
          if (!item) return;
          const modalEl = document.getElementById('taskModal');
          const titleEl = document.getElementById('taskModalTitle');
          const projEl = document.getElementById('taskProject');
          const assigneeEl = document.getElementById('taskAssignee');
          const dueEl = document.getElementById('taskDue');
          const prioEl = document.getElementById('taskPriority');
          if (titleEl) titleEl.textContent = item.getAttribute('data-title') || 'Task Details';
          if (projEl) projEl.textContent = item.getAttribute('data-projectname') || '';
          const descEl = document.getElementById('taskDescription');
          if (descEl) descEl.textContent = item.getAttribute('data-description') || '';
          if (assigneeEl) assigneeEl.textContent = item.getAttribute('data-assignee') || 'Unassigned';
          if (dueEl) dueEl.textContent = item.getAttribute('data-due') || '';
          if (prioEl) prioEl.textContent = item.getAttribute('data-priority') || '';
          const catEl = document.getElementById('taskCategory');
          if (catEl) catEl.textContent = item.getAttribute('data-category') || '';
          if (modalEl) { const m = new bootstrap.Modal(modalEl); m.show(); }
        } else if (action === 'comment') {
          const modalEl = document.getElementById('commentModal');
          if (modalEl) {
            const taskInput = document.getElementById('commentTaskId');
            if (taskInput) taskInput.value = taskId || '';
            const m = new bootstrap.Modal(modalEl);
            m.show();
          }
        } else if (action === 'time') {
          const modalEl = document.getElementById('timeLogModal');
          if (modalEl) {
            const taskInput = document.getElementById('timeLogTaskId');
            if (taskInput) taskInput.value = taskId || '';
            const m = new bootstrap.Modal(modalEl);
            m.show();
          }
        } else if (action === 'block') {
          const modalEl = document.getElementById('blockModal');
          if (modalEl) {
            const taskInput = document.getElementById('blockTaskId');
            if (taskInput) taskInput.value = taskId || '';
            const m = new bootstrap.Modal(modalEl);
            m.show();
          }
        } else if (action === 'add-subtask') {
          const item = target.closest('.kanban-item');
          const modalEl = document.getElementById('subtaskModal');
          if (item && modalEl) {
            const parentId = item.getAttribute('data-id') || '';
            const projectId = item.getAttribute('data-projectid') || '';
            const parentTitle = item.getAttribute('data-title') || '';
            const projName = item.getAttribute('data-projectname') || '';
            const parentInput = document.getElementById('subtaskParentId');
            const projectInput = document.getElementById('subtaskProjectId');
            const titleInput = document.getElementById('subtaskTitleInput');
            if (parentInput) parentInput.value = parentId;
            if (projectInput) projectInput.value = projectId;
            if (titleInput && parentTitle) titleInput.placeholder = 'Subtask of: ' + parentTitle;
            const m = new bootstrap.Modal(modalEl);
            m.show();
          }
        } else if (action === 'request-extension') {
          const item = target.closest('.kanban-item');
          const modalEl = document.getElementById('newRequestModal');
          if (item && modalEl) {
            const tTitle = item.getAttribute('data-title') || '';
            const pName = item.getAttribute('data-projectname') || '';
            const due = item.getAttribute('data-due') || '';
            const titleEl = document.getElementById('reqTaskTitle');
            const projEl = document.getElementById('reqProject');
            const curEl = document.getElementById('reqCurrentDue');
            const newEl = document.getElementById('reqNewDue');
            if (titleEl) titleEl.value = tTitle;
            if (projEl) projEl.value = pName;
            if (curEl) curEl.value = due;
            if (newEl) newEl.value = due;
            const m = new bootstrap.Modal(modalEl); m.show();
          }
        } else if (action === 'edit-project') {
          const btn = target;
          const projId = btn.getAttribute('data-projectid') || '';
          const name = btn.getAttribute('data-name') || '';
          const desc = btn.getAttribute('data-description') || '';
          const category = btn.getAttribute('data-category') || '';
          const priority = btn.getAttribute('data-priority') || 'medium';
          const status = btn.getAttribute('data-status') || 'planning';
          const start = btn.getAttribute('data-start') || '';
          const due = btn.getAttribute('data-due') || '';
          const teamId = btn.getAttribute('data-teamid') || '';
          const form = document.getElementById('newProjectForm');
          if (!form) return;
          const titleEl = form.querySelector('.modal-title');
          const submitBtn = form.querySelector('button[type="submit"]');
          const actionInput = form.querySelector('input[name="action"]');
          const idInput = form.querySelector('#projId');
          document.getElementById('projName').value = name;
          document.getElementById('projDesc').value = desc;
          document.getElementById('projCategory').value = category;
          document.getElementById('projPriority').value = priority;
          document.getElementById('projStatus').value = status;
          document.getElementById('projStart').value = start;
          document.getElementById('projDue').value = due;
          const teamSelect = document.getElementById('projTeamSelect');
          if (teamSelect) teamSelect.value = teamId || '';
          if (idInput) idInput.value = projId;
          if (actionInput) actionInput.value = 'update_project';
          if (titleEl) titleEl.textContent = 'Edit Project';
          if (submitBtn) submitBtn.textContent = 'Update Project';
          const modalEl = document.getElementById('newProjectModal');
          if (modalEl) { const m = new bootstrap.Modal(modalEl); m.show(); }
        } else if (action === 'delete-project') {
          const projId = target.getAttribute('data-projectid');
          if (!projId) return;
          if (!confirm('Delete this project? This cannot be undone.')) return;
          const form = document.getElementById('projectDeleteForm');
          const idInput = document.getElementById('projectDeleteId');
          if (form && idInput) { idInput.value = projId; form.submit(); }
        } else if (action === 'extension-status') {
          const reqId = target.getAttribute('data-requestid');
          const st = target.getAttribute('data-status');
          const form = document.getElementById('extensionStatusForm');
          if (!reqId || !st || !form) return;
          document.getElementById('extensionRequestId').value = reqId;
          document.getElementById('extensionNewStatus').value = st;
          form.submit();
        } else if (action === 'assign-team') {
          const projId = target.getAttribute('data-projectid') || '';
          const pidInput = document.getElementById('assignTeamProjectId');
          if (pidInput) pidInput.value = projId;
          const modalEl = document.getElementById('assignTeamModal');
          if (modalEl) { const m = new bootstrap.Modal(modalEl); m.show(); }
        } else if (action === 'edit-task') {
          const item = target.closest('.kanban-item');
          if (!item) return;
          const id = item.getAttribute('data-id') || '';
          const modalEl = document.getElementById('editTaskModal');
          document.getElementById('editTaskId').value = id;
          document.getElementById('editTaskTitle').value = item.getAttribute('data-title') || '';
          document.getElementById('editTaskDesc').value = item.getAttribute('data-description') || '';
          document.getElementById('editTaskCategory').value = item.getAttribute('data-category') || '';
          document.getElementById('editTaskDue').value = item.getAttribute('data-due') || '';
          document.getElementById('editTaskPriority').value = item.getAttribute('data-priority') || 'medium';
          document.getElementById('editTaskStatus').value = item.getAttribute('data-status') || 'todo';
          if (modalEl) { const m = new bootstrap.Modal(modalEl); m.show(); }
        } else if (action === 'assign-users') {
          const item = target.closest('.kanban-item');
          if (!item) return;
          const id = item.getAttribute('data-id') || '';
          document.getElementById('assignTaskId').value = id;
          const modalEl = document.getElementById('assignUsersModal');
          if (modalEl) { const m = new bootstrap.Modal(modalEl); m.show(); }
        } else if (action === 'upload-asset') {
          const item = target.closest('.kanban-item');
          if (!item) return;
          const id = item.getAttribute('data-id') || '';
          document.getElementById('uploadAssetTaskId').value = id;
          const modalEl = document.getElementById('uploadAssetModal');
          if (modalEl) { const m = new bootstrap.Modal(modalEl); m.show(); }
        } else if (action === 'view-assets') {
          const item = target.closest('.kanban-item');
          if (!item) return;
          const id = item.getAttribute('data-id') || '';
          fetch('project_task.php?ajax=attachments&task_id=' + encodeURIComponent(id))
            .then(r => r.json()).then(list => {
              const body = document.getElementById('assetsListBody');
              const frag = document.createDocumentFragment();
              const wrap = document.createElement('div');
              if (!list || list.length === 0) { wrap.innerHTML = '<div class="alert alert-light border">No assets</div>'; }
              else {
                const rows = list.map(a => '<div class="d-flex justify-content-between align-items-center border p-2 mb-2"><div><div class="fw-medium">'+(a.file_name||'')+'</div><small class="text-muted">'+(a.mime_type||'')+' • '+(a.file_size||0)+' bytes</small></div><div class="btn-group"><a class="btn btn-sm btn-outline-secondary" href="'+(a.file_path||'#')+'" target="_blank">Open</a><form method="POST" class="d-inline"><input type="hidden" name="action" value="delete_task_asset"><input type="hidden" name="attachment_id" value="'+String(a.id)+'"><button class="btn btn-sm btn-outline-danger">Remove</button></form></div></div>').join('');
                wrap.innerHTML = rows;
              }
              frag.appendChild(wrap);
              body.innerHTML = '';
              body.appendChild(frag);
              const modalEl = document.getElementById('assetsListModal');
              if (modalEl) { const m = new bootstrap.Modal(modalEl); m.show(); }
            }).catch(() => { alert('Failed to load assets'); });
        } else if (action === 'trash-task') {
          const id = target.getAttribute('data-taskid');
          if (!id) return;
          if (!confirm('Move this task to trash?')) return;
          const form = document.getElementById('trashTaskForm');
          document.getElementById('trashTaskId').value = id;
          form.submit();
        } else if (action === 'delete-task') {
          const id = target.getAttribute('data-taskid');
          if (!id) return;
          if (!confirm('Delete permanently? This cannot be undone.')) return;
          const form = document.getElementById('deleteTaskForm');
          document.getElementById('deleteTaskId').value = id;
          form.submit();
        }
      });
      // Clear project filter when user clicks the Kanban tab directly
      const kanbanTab = document.getElementById('tab-kanban');
      kanbanTab?.addEventListener('click', () => { kanbanProjectFilter = null; setTimeout(() => { applyKanbanFilter(); }, 50); });
      applyKanbanFilter();
    })();

    // Kanban drag-and-drop to update task status
    (function(){
      const statusForm = document.getElementById('taskStatusForm');
      const taskIdInput = document.getElementById('taskStatusTaskId');
      const statusInput = document.getElementById('taskStatusNew');
      const items = document.querySelectorAll('.kanban-item');
      const columns = document.querySelectorAll('.kanban-column');

      items.forEach(item => {
        item.setAttribute('draggable', 'true');
        item.addEventListener('dragstart', e => {
          item.classList.add('dragging');
          const id = item.getAttribute('data-id') || '';
          e.dataTransfer?.setData('text/plain', id);
        });
        item.addEventListener('dragend', () => {
          item.classList.remove('dragging');
        });
      });

      columns.forEach(col => {
        const body = col.querySelector('.card-body');
        if (col.getAttribute('data-dnd-wired') === '1') return;
        col.setAttribute('data-dnd-wired','1');
        col.addEventListener('dragover', e => {
          e.preventDefault();
          col.classList.add('drag-over');
        });
        col.addEventListener('dragleave', () => {
          col.classList.remove('drag-over');
        });
        col.addEventListener('drop', async e => {
          e.preventDefault();
          col.classList.remove('drag-over');
          const id = e.dataTransfer?.getData('text/plain');
          const newStatus = col.getAttribute('data-status') || '';
          if (!id || !newStatus) return;
          try {
            const form = document.getElementById('taskStatusForm');
            const idInput = document.getElementById('taskStatusTaskId');
            const statusInput = document.getElementById('taskStatusNew');
            if (!form || !idInput || !statusInput) return;
            idInput.value = String(parseInt(id, 10));
            statusInput.value = String(newStatus);
            form.submit();
          } catch (err) {
            alert('Failed to update status. Please try again.');
          }
        });
      });
    })();

    // Extensions: client-side status filter
    (function(){
      const container = document.getElementById('requestsContainer');
      const btns = Array.from(document.querySelectorAll('.filter-btn'));
      function apply(status){
        const cards = Array.from(container?.querySelectorAll('.col-12.col-md-6') || []);
        let visible = 0;
        cards.forEach(card => {
          const st = (card.getAttribute('data-status') || '').toLowerCase();
          const show = !status || status === 'all' || st === status.toLowerCase();
          card.style.display = show ? '' : 'none';
          if (show) visible++;
        });
        const emptyAlert = container?.querySelector('.alert');
        if (emptyAlert) emptyAlert.parentElement.style.display = visible === 0 ? '' : 'none';
      }
      btns.forEach(btn => {
        btn.addEventListener('click', () => {
          btns.forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          apply(btn.getAttribute('data-status') || 'all');
        });
      });
      apply('all');
    })();

    (function(){
      const modal = document.getElementById('newProjectModal');
      if (!modal) return;
      modal.addEventListener('show.bs.modal', function(){
        const form = document.getElementById('newProjectForm');
        const actionInput = form?.querySelector('input[name="action"]');
        const actionVal = actionInput?.value || '';
        if (actionVal !== 'create_project') return;
        const startEl = document.getElementById('projStart');
        const dueEl = document.getElementById('projDue');
        const today = new Date();
        function fmt(d){ return d.toISOString().slice(0,10); }
        const min = fmt(today);
        if (startEl) { startEl.value = fmt(today); startEl.setAttribute('min', min); }
        if (dueEl) { const d2 = new Date(today.getTime() + 7*24*60*60*1000); dueEl.value = fmt(d2); dueEl.setAttribute('min', min); }
      });
    })();
  </script>
  
  <form id="taskStatusForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="update_task_status">
    <input type="hidden" name="task_id" id="taskStatusTaskId">
    <input type="hidden" name="new_status" id="taskStatusNew">
  </form>
  <form id="trashTaskForm" method="POST" style="display:none"><input type="hidden" name="action" value="trash_task"><input type="hidden" name="task_id" id="trashTaskId"></form>
  <form id="deleteTaskForm" method="POST" style="display:none"><input type="hidden" name="action" value="delete_task_permanent"><input type="hidden" name="task_id" id="deleteTaskId"></form>
  
  <form id="projectDeleteForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="delete_project">
    <input type="hidden" name="project_id" id="projectDeleteId">
  </form>
  
  <form id="extensionStatusForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="update_extension_status">
    <input type="hidden" name="request_id" id="extensionRequestId">
    <input type="hidden" name="new_status" id="extensionNewStatus">
  </form>
<?php include 'includes/footer.php'; ?>
