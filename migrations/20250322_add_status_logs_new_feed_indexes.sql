ALTER TABLE `status_logs_new`
    ADD KEY `idx_status_logs_new_user_created` (`user_id`, `created_at`),
    ADD KEY `idx_status_logs_new_user_id` (`user_id`, `id`);
    
    
    
    
    
    
            <div class="status-area">
            <div class="status-header-row">
                <h3 class="status-header">Status Updates</h3>
            </div>
            <div class="status-list">
                <div class="status-item">
                    <span class="material-icons-outlined status-icon text-success">check_circle</span>
                    <div class="status-content">
                        <p class="status-text">Bild hochgeladen: product_1.jpg</p>
                        <p class="status-time">vor 2 Minuten</p>
                    </div>
                </div>
                <div class="status-item">
                    <span class="material-icons-outlined status-icon text-info">sync</span>
                    <div class="status-content">
                        <p class="status-text">Bestellung #123 wird verarbeitet</p>
                        <p class="status-time">vor 15 Minuten</p>
                    </div>
                </div>
                <div class="status-item">
                    <span class="material-icons-outlined status-icon text-error">error</span>
                    <div class="status-content">
                        <p class="status-text">Zahlung fehlgeschlagen #122</p>
                        <p class="status-time">vor 1 Stunde</p>
                    </div>
                </div>
                <div class="status-item">
                    <span class="material-icons-outlined status-icon text-success">waving_hand</span>
                    <div class="status-content">
                        <p class="status-text">Neuer Account erstellt</p>
                        <p class="status-time">vor 3 Stunden</p>
                    </div>
                </div>
            </div>
        </div>
