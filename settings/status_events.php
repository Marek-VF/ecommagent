<?php

return [

    'WORKFLOW_START_FAILED' => [
        'label'     => 'Workflow konnte nicht gestartet werden.',
        'severity'  => 'error',
        'icon_html' => '<span class="material-icons-outlined status-icon text-danger">error_outline</span>',
    ],

    'WORKFLOW_STARTED' => [
        'label'     => 'Workflow wurde gestartet.',
        'severity'  => 'info',
        'icon_html' => '<span class="material-icons-outlined status-icon text-info">play_circle</span>',
    ],

    'WORKFLOW_PENDING' => [
        'label'     => 'Workflow bereit zum Start.',
        'severity'  => 'info',
        'icon_html' => '<span class="material-icons-outlined status-icon text-info">hourglass_empty</span>',
    ],

    'WORKFLOW_RUNNING' => [
        'label'     => 'Verarbeitung läuft …',
        'severity'  => 'info',
        'icon_html' => '<span class="material-icons-outlined status-icon text-info">autorenew</span>',
    ],

    'WORKFLOW_COMPLETED' => [
        'label'     => 'Workflow abgeschlossen.',
        'severity'  => 'success',
        'icon_html' => '<span class="material-icons-outlined status-icon text-success">check_circle</span>',
    ],

    'WORKFLOW_FINISHED_SUCCESS' => [
        'label'     => 'Workflow erfolgreich abgeschlossen.',
        'severity'  => 'success',
        'icon_html' => '<span class="material-icons-outlined status-icon text-success">check_circle</span>',
    ],

    'WORKFLOW_FINISHED_ERROR' => [
        'label'     => 'Workflow mit Fehlern beendet.',
        'severity'  => 'error',
        'icon_html' => '<span class="material-icons-outlined status-icon text-danger">highlight_off</span>',
    ],

    'ANALYSIS_STARTED' => [
        'label'     => 'Analyse der Bilder gestartet.',
        'severity'  => 'info',
        'icon_html' => '<span class="material-icons-outlined status-icon text-info">analytics</span>',
    ],

    'ANALYSIS_FINISHED' => [
        'label'     => 'Analyse der Bilder abgeschlossen.',
        'severity'  => 'success',
        'icon_html' => '<span class="material-icons-outlined status-icon text-success">analytics</span>',
    ],

    'PROMPT_VARIANTS_FETCHED' => [
        'label'     => 'Prompt-Varianten erfolgreich geladen.',
        'severity'  => 'info',
        'icon_html' => '<span class="material-icons-outlined status-icon text-info">tune</span>',
    ],

    'PROMPT_VARIANTS_ERROR' => [
        'label'     => 'Fehler beim Laden der Prompt-Varianten.',
        'severity'  => 'error',
        'icon_html' => '<span class="material-icons-outlined status-icon text-danger">tune</span>',
    ],

    'IMAGE_SLOT_1' => [
        'label'     => 'Bild für Slot 1 wurde erfolgreich generiert.',
        'severity'  => 'success',
        'icon_html' => '<span class="material-icons-outlined status-icon text-success">image</span>',
    ],

    'IMAGE_SLOT_2' => [
        'label'     => 'Bild für Slot 2 wurde erfolgreich generiert.',
        'severity'  => 'success',
        'icon_html' => '<span class="material-icons-outlined status-icon text-success">image</span>',
    ],

    'IMAGE_SLOT_3' => [
        'label'     => 'Bild für Slot 3 wurde erfolgreich generiert.',
        'severity'  => 'success',
        'icon_html' => '<span class="material-icons-outlined status-icon text-success">image</span>',
    ],

    'IMAGE_SLOT_4' => [
        'label'     => 'Bild für Slot 4 wurde erfolgreich generiert.',
        'severity'  => 'success',
        'icon_html' => '<span class="material-icons-outlined status-icon text-success">image</span>',
    ],

    'IMAGE_ERROR' => [
        'label'     => 'Die Bildgenerierung ist fehlgeschlagen.',
        'severity'  => 'error',
        'icon_html' => '<span class="material-icons-outlined status-icon text-danger">broken_image</span>',
    ],

    'CREDITS_CHECK_FAILED' => [
        'label'     => 'Credit-Prüfung konnte nicht durchgeführt werden.',
        'severity'  => 'error',
        'icon_html' => '<span class="material-icons-outlined status-icon text-danger">payments</span>',
    ],

    'CREDITS_NOT_ENOUGH' => [
        'label'     => 'Nicht genügend Credits für diesen Workflow.',
        'severity'  => 'warning',
        'icon_html' => '<span class="material-icons-outlined status-icon text-warning">warning</span>',
    ],

    'CREDITS_DEDUCTED' => [
        'label'     => 'Credits für diesen Workflow wurden abgebucht.',
        'severity'  => 'info',
        'icon_html' => '<span class="material-icons-outlined status-icon text-info">payments</span>',
    ],

    'UPLOAD_STARTED' => [
        'label'     => 'Bild wird hochgeladen …',
        'severity'  => 'info',
        'icon_html' => '<span class="material-icons-outlined status-icon text-info">upload</span>',
    ],

    'UPLOAD_SUCCESS' => [
        'label'     => 'Upload erfolgreich – Workflow kann gestartet werden.',
        'severity'  => 'success',
        'icon_html' => '<span class="material-icons-outlined status-icon text-success">cloud_upload</span>',
    ],

    'UPLOAD_FAILED' => [
        'label'     => 'Upload fehlgeschlagen.',
        'severity'  => 'error',
        'icon_html' => '<span class="material-icons-outlined status-icon text-danger">error</span>',
    ],

    'UPLOAD_HTTP_404' => [
        'label'     => 'Upload fehlgeschlagen (Server meldet 404).',
        'severity'  => 'error',
        'icon_html' => '<span class="material-icons-outlined status-icon text-danger">report</span>',
    ],

    'UPLOAD_HTTP_500' => [
        'label'     => 'Upload fehlgeschlagen (Serverfehler).',
        'severity'  => 'error',
        'icon_html' => '<span class="material-icons-outlined status-icon text-danger">dns</span>',
    ],

    'FRONTEND_NETWORK_ERROR' => [
        'label'     => 'Netzwerkfehler – bitte Verbindung prüfen.',
        'severity'  => 'warning',
        'icon_html' => '<span class="material-icons-outlined status-icon text-warning">wifi_off</span>',
    ],

    'BACKEND_UNEXPECTED_ERROR' => [
        'label'     => 'Ein unerwarteter Fehler ist aufgetreten.',
        'severity'  => 'error',
        'icon_html' => '<span class="material-icons-outlined status-icon text-danger">error</span>',
    ],

    'STATUS_POLLING_ERROR' => [
        'label'     => 'Fehler beim Abrufen des Status.',
        'severity'  => 'error',
        'icon_html' => '<span class="material-icons-outlined status-icon text-danger">warning</span>',
    ],

    'READY_FOR_UPLOAD' => [
        'label'     => 'Bereit zum Upload.',
        'severity'  => 'info',
        'icon_html' => '<span class="material-icons-outlined status-icon text-info">cloud_upload</span>',
    ],

    'AUTH_SESSION_EXPIRED' => [
        'label'     => 'Deine Sitzung ist abgelaufen. Bitte neu einloggen.',
        'severity'  => 'warning',
        'icon_html' => '<span class="material-icons-outlined status-icon text-warning">lock</span>',
    ],

    'UNKNOWN' => [
        'label'     => 'Unbekannter Status.',
        'severity'  => 'info',
        'icon_html' => '<span class="material-icons-outlined status-icon text-info">info</span>',
    ],

];

