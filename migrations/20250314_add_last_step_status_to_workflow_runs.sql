ALTER TABLE workflow_runs
  ADD COLUMN last_step_status ENUM('success', 'error') NULL DEFAULT NULL;
