<?php

declare(strict_types=1);

/**
 * AWS Event-in-a-Box - EPM API Helper Functions
 *
 * This file contains functions for interacting with:
 * 1. EPM INTERNAL API (JWT auth) - /epm/* endpoints
 * 2. Kaltura PUBLIC API (KS auth) - /api_v3/* endpoints
 *
 * Authentication is handled separately - JWT token is provided via config/session
 *
 * @package AWS_Event_in_a_Box
 * @author  Kaltura Solutions Team
 * @version 1.0.0
 */

// API Type Constants
const API_TYPE_INTERNAL = 'EPM_INTERNAL'; // JWT authenticated
const API_TYPE_PUBLIC = 'KALTURA_PUBLIC'; // KS authenticated

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Set CORS headers for development environment only
 *
 * @return void
 */
function setCORSHeaders(): void
{
    if (!defined('ALLOW_CORS') || !ALLOW_CORS) {
        return;
    }

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Log debug message to audit log
 *
 * @param string $message Debug message
 * @return void
 */
function logDebug(string $message): void
{
    if (!defined('ENABLE_AUDIT_LOG') || !ENABLE_AUDIT_LOG) {
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [DEBUG] {$message}\n";
    error_log($logMessage, 3, AUDIT_LOG_PATH);
}

/**
 * Log error message to audit log
 *
 * @param string $message Error message
 * @return void
 */
function logError(string $message): void
{
    if (!defined('ENABLE_AUDIT_LOG') || !ENABLE_AUDIT_LOG) {
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [ERROR] {$message}\n";
    error_log($logMessage, 3, AUDIT_LOG_PATH);
}

/**
 * Log audit event (business actions like speaker invites, page updates)
 *
 * @param string $action Action performed
 * @param string $userId User ID or identifier
 * @param array|string $details Additional details
 * @return void
 */
function logAudit(string $action, string $userId, $details): void
{
    if (!defined('ENABLE_AUDIT_LOG') || !ENABLE_AUDIT_LOG) {
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $detailsJson = is_array($details) ? json_encode($details) : (string) $details;
    $logMessage = "[{$timestamp}] [AUDIT] Action: {$action} | User: {$userId} | Details: {$detailsJson}\n";
    error_log($logMessage, 3, AUDIT_LOG_PATH);
}

/**
 * Validate JSON payload
 *
 * @param mixed $payload JSON string or array
 * @return array{valid: bool, error?: string, data?: array}
 */
function validatePayload($payload): array
{
    if (empty($payload)) {
        return ['valid' => false, 'error' => 'Empty payload'];
    }

    if (is_array($payload)) {
        return ['valid' => true, 'data' => $payload];
    }

    $decoded = json_decode((string) $payload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['valid' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()];
    }

    return ['valid' => true, 'data' => $decoded];
}

// ============================================================================
// CORE API FUNCTIONS
// ============================================================================

/**
 * Get JWT token from session cache or config
 *
 * API Type: N/A (utility function)
 *
 * @return string JWT token (may be empty if not configured)
 */
function getJWTToken(): string
{
    // Check session cache first
    if (isset($_SESSION['jwt_token'], $_SESSION['jwt_expiry'])) {
        if (time() < $_SESSION['jwt_expiry']) {
            logDebug("Using cached JWT token");
            return (string) $_SESSION['jwt_token'];
        }
    }

    // Fall back to config/env
    $jwt = defined('EPM_JWT_TOKEN') ? EPM_JWT_TOKEN : '';

    if (!empty($jwt)) {
        // Cache in session
        $_SESSION['jwt_token'] = $jwt;
        $_SESSION['jwt_expiry'] = time() + (defined('JWT_CACHE_EXPIRY') ? JWT_CACHE_EXPIRY : 3600);
        logDebug("JWT token cached (expires in " . JWT_CACHE_EXPIRY . "s)");
    }

    return $jwt;
}

/**
 * Call EPM Internal API with JWT authentication
 *
 * API Type: INTERNAL (JWT + x-eventId header)
 * Base URL: EPM_API_BASE_URL (https://epm.{region}.ovp.kaltura.com)
 *
 * @param string $endpoint API endpoint (e.g., '/epm/eventUsers/inviteUser')
 * @param array|string $payload Request payload
 * @param string|null $eventId Optional event ID for x-eventId header
 * @return string JSON response
 */
function callEPMAPI(string $endpoint, $payload, ?string $eventId = null): string
{
    $url = EPM_API_BASE_URL . $endpoint;
    $jwt = getJWTToken();

    if (empty($jwt)) {
        logError("callEPMAPI: JWT token not configured");
        return json_encode(['error' => 'JWT token not configured']);
    }

    // Convert payload to JSON if it's an array
    $jsonPayload = is_array($payload) ? json_encode($payload) : (string) $payload;

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $jwt,
        'Content-Length: ' . strlen($jsonPayload)
    ];

    if ($eventId !== null) {
        $headers[] = 'x-eventId: ' . $eventId;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        logError("callEPMAPI: Failed to initialize CURL");
        return json_encode(['error' => 'Failed to initialize HTTP client']);
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($result === false || !empty($curlError)) {
        logError("CURL error calling EPM {$endpoint}: {$curlError}");
        return json_encode(['error' => 'EPM API call failed: ' . $curlError]);
    }

    logDebug("EPM API call: {$endpoint} - Status: {$httpCode}");

    return (string) $result;
}

/**
 * Call Event Platform Public API with KS authentication
 *
 * API Type: PUBLIC (KS authentication)
 * Base URL: EP_API_BASE_URL (https://events-api.{region}.ovp.kaltura.com)
 *
 * @param string $endpoint API endpoint (e.g., '/api/v1/events/create')
 * @param array|string $payload Request payload
 * @param string $ks Kaltura Session token (KS)
 * @return string JSON response
 */
function callEPPublicAPI(string $endpoint, $payload, string $ks): string
{
    $url = EP_API_BASE_URL . $endpoint;

    if (empty($ks)) {
        logError("callEPPublicAPI: KS token not provided");
        return json_encode(['error' => 'KS token required']);
    }

    // Convert payload to JSON if it's an array
    $jsonPayload = is_array($payload) ? json_encode($payload) : (string) $payload;

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $ks,
        'Content-Length: ' . strlen($jsonPayload)
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        logError("callEPPublicAPI: Failed to initialize CURL");
        return json_encode(['error' => 'Failed to initialize HTTP client']);
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($result === false || !empty($curlError)) {
        logError("CURL error calling EP Public {$endpoint}: {$curlError}");
        return json_encode(['error' => 'EP Public API call failed: ' . $curlError]);
    }

    logDebug("EP Public API call: {$endpoint} - Status: {$httpCode}");

    return (string) $result;
}

/**
 * Call Kaltura Public API for upload operations
 *
 * API Type: PUBLIC (KS authentication)
 * Base URL: KALTURA_API_URL (https://www.kaltura.com/api_v3)
 *
 * @param string $endpoint API endpoint (e.g., '/service/uploadtoken/action/upload')
 * @param array $params Request parameters including 'ks' and 'uploadTokenId'
 * @return string JSON response
 */
function callKalturaUploadAPI(string $endpoint, array $params): string
{
    $url = KALTURA_API_URL . $endpoint;

    $ch = curl_init($url);
    if ($ch === false) {
        logError("callKalturaUploadAPI: Failed to initialize CURL");
        return json_encode(['error' => 'Failed to initialize HTTP client']);
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 300 // 5 minutes for file uploads
    ]);

    $result = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($result === false || !empty($curlError)) {
        logError("CURL error calling Kaltura {$endpoint}: {$curlError}");
        return json_encode(['error' => 'Kaltura API call failed: ' . $curlError]);
    }

    logDebug("Kaltura Upload API call: {$endpoint} - Status: {$httpCode}");

    return (string) $result;
}

// ============================================================================
// EVENT & SESSION MANAGEMENT FUNCTIONS (PUBLIC API)
// ============================================================================

/**
 * Create a new event
 *
 * API Type: PUBLIC
 * Endpoint: POST /api/v1/events/create
 *
 * @param array $eventData Event data (name, description, startDate, endDate, timezone, templateId, etc.)
 * @param string $ks Kaltura Session token (KS)
 * @return array{success: bool, eventId: string|null, publicDomain: string|null, error: string|null}
 */
function createEvent(array $eventData, string $ks): array
{
    try {
        // Validate required fields
        $requiredFields = ['name', 'startDate', 'endDate', 'timezone'];
        foreach ($requiredFields as $field) {
            if (empty($eventData[$field])) {
                return [
                    'success' => false,
                    'eventId' => null,
                    'publicDomain' => null,
                    'error' => "Missing required field: {$field}"
                ];
            }
        }

        // Build payload
        $payload = [
            'name' => trim($eventData['name']),
            'startDate' => $eventData['startDate'],
            'endDate' => $eventData['endDate'],
            'timezone' => $eventData['timezone'],
            'description' => $eventData['description'] ?? '',
            'templateId' => $eventData['templateId'] ?? null,
            'doorsOpenDate' => $eventData['doorsOpenDate'] ?? null
        ];

        // Remove null values
        $payload = array_filter($payload, fn($value) => $value !== null);

        // Call EP PUBLIC API
        $result = callEPPublicAPI('/api/v1/events/create', $payload, $ks);
        $data = json_decode($result, true);

        if (isset($data['status']) && $data['status'] === 'ok' && isset($data['event'])) {
            $event = $data['event'];
            logAudit('EVENT_CREATED', 'system', [
                'eventId' => $event['id'],
                'eventName' => $event['name']
            ]);

            return [
                'success' => true,
                'eventId' => (string) $event['id'],
                'publicDomain' => $event['publicDomain'] ?? null,
                'error' => null
            ];
        }

        $error = $data['error'] ?? 'Unknown error creating event';
        logError("Failed to create event: {$error}");

        return [
            'success' => false,
            'eventId' => null,
            'publicDomain' => null,
            'error' => $error
        ];
    } catch (Exception $e) {
        logError("createEvent exception: " . $e->getMessage());
        return [
            'success' => false,
            'eventId' => null,
            'publicDomain' => null,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get EPM and KMS URLs for an event
 *
 * API Type: INTERNAL
 * Endpoint: POST /epm/events/list
 *
 * After creating an event (PUBLIC API), use this to get management URLs:
 * - EPM URL: For managing event in Event Platform Manager
 * - KMS URL: Public-facing event site URL
 *
 * @param string $kalturaEventId Event ID from createEvent() (kalturaId)
 * @return array{success: bool, epmUrl: string|null, kmsUrl: string|null, epmId: string|null, error: string|null}
 */
function getEventUrls(string $kalturaEventId): array
{
    try {
        $payload = [
            'page' => 1,
            'pageSize' => 1,
            'filter' => [
                'kalturaEventIdIn' => [(int) $kalturaEventId]
            ]
        ];

        // Call EPM INTERNAL API (no x-eventId header needed for list)
        $result = callEPMAPI('/epm/events/list', $payload, null);
        $data = json_decode($result, true);

        if (isset($data['status']) && $data['status'] === 'ok' && !empty($data['events'])) {
            $event = $data['events'][0];
            $epmId = $event['_id'];
            $hostName = $event['hostName'];

            // Build URLs
            $epmUrl = "https://eventplatform.kaltura.com/{$epmId}/overview";
            $kmsUrl = $hostName;

            logDebug("Event URLs retrieved: EPM={$epmUrl}, KMS={$kmsUrl}");

            return [
                'success' => true,
                'epmUrl' => $epmUrl,
                'kmsUrl' => $kmsUrl,
                'epmId' => $epmId,
                'error' => null
            ];
        }

        if (empty($data['events'])) {
            $error = 'Event not found in EPM. It may take a few seconds to sync.';
        } else {
            $error = $data['error'] ?? 'Failed to retrieve event URLs';
        }

        logError("getEventUrls failed: {$error}");

        return [
            'success' => false,
            'epmUrl' => null,
            'kmsUrl' => null,
            'epmId' => null,
            'error' => $error
        ];
    } catch (Exception $e) {
        logError("getEventUrls exception: " . $e->getMessage());
        return [
            'success' => false,
            'epmUrl' => null,
            'kmsUrl' => null,
            'epmId' => null,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Create a new session (agenda item)
 *
 * API Type: PUBLIC
 * Endpoint: POST /api/v1/sessions/create
 *
 * @param string $eventId Event ID
 * @param array $sessionData Session data (name, type, startDate, endDate, description, etc.)
 * @param string $ks Kaltura Session token (KS)
 * @return array{success: bool, sessionId: string|null, error: string|null}
 */
function createSession(string $eventId, array $sessionData, string $ks): array
{
    try {
        // Validate required fields
        $requiredFields = ['name', 'type', 'startDate', 'endDate'];
        foreach ($requiredFields as $field) {
            if (empty($sessionData[$field])) {
                return [
                    'success' => false,
                    'sessionId' => null,
                    'error' => "Missing required field: {$field}"
                ];
            }
        }

        // Validate session type
        $validTypes = ['LiveWebcast', 'SimuLive', 'MeetingEntry', 'LiveKME'];
        if (!in_array($sessionData['type'], $validTypes, true)) {
            return [
                'success' => false,
                'sessionId' => null,
                'error' => "Invalid session type. Must be one of: " . implode(', ', $validTypes)
            ];
        }

        // Build payload
        $payload = [
            'eventId' => (int) $eventId,
            'session' => [
                'name' => trim($sessionData['name']),
                'type' => $sessionData['type'],
                'startDate' => $sessionData['startDate'],
                'endDate' => $sessionData['endDate'],
                'description' => $sessionData['description'] ?? '',
                'visibility' => $sessionData['visibility'] ?? 'published',
                'isManualLive' => $sessionData['isManualLive'] ?? false
            ]
        ];

        // Add optional fields if present
        if (!empty($sessionData['imageUrlEntryId'])) {
            $payload['session']['imageUrlEntryId'] = $sessionData['imageUrlEntryId'];
        }

        if (!empty($sessionData['sourceEntryId'])) {
            $payload['session']['sourceEntryId'] = $sessionData['sourceEntryId'];
        }

        // Call EP PUBLIC API
        $result = callEPPublicAPI('/api/v1/sessions/create', $payload, $ks);
        $data = json_decode($result, true);

        if (isset($data['status']) && $data['status'] === 'ok' && isset($data['session'])) {
            $session = $data['session'];
            logAudit('SESSION_CREATED', 'system', [
                'eventId' => $eventId,
                'sessionId' => $session['id'],
                'sessionName' => $session['name'],
                'sessionType' => $session['type']
            ]);

            return [
                'success' => true,
                'sessionId' => $session['id'],
                'error' => null
            ];
        }

        $error = $data['error'] ?? 'Unknown error creating session';
        logError("Failed to create session: {$error}");

        return [
            'success' => false,
            'sessionId' => null,
            'error' => $error
        ];
    } catch (Exception $e) {
        logError("createSession exception: " . $e->getMessage());
        return [
            'success' => false,
            'sessionId' => null,
            'error' => $e->getMessage()
        ];
    }
}

// ============================================================================
// SPEAKER MANAGEMENT FUNCTIONS (INTERNAL API)
// ============================================================================

/**
 * Invite a speaker/user to an event
 *
 * API Type: INTERNAL
 * Endpoint: POST /epm/eventUsers/inviteUser
 *
 * @param string $eventId Event ID
 * @param array $speakerData Speaker information (firstName, lastName, email, title, company, bio, roles)
 * @param string|null $imageEntryId Optional Kaltura entry ID for speaker image
 * @param bool $skipEmail Whether to skip invitation email
 * @return array{success: bool, userId: string|null, error: string|null}
 */
function inviteSpeakerToEvent(
    string $eventId,
    array $speakerData,
    ?string $imageEntryId = null,
    bool $skipEmail = true
): array {
    try {
        // Validate required fields
        $requiredFields = ['firstName', 'lastName', 'email'];
        foreach ($requiredFields as $field) {
            if (empty($speakerData[$field])) {
                return [
                    'success' => false,
                    'userId' => null,
                    'error' => "Missing required field: {$field}"
                ];
            }
        }

        // Validate email format
        if (!filter_var($speakerData['email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'userId' => null,
                'error' => 'Invalid email address format'
            ];
        }

        // Build payload
        $payload = [
            'user' => [
                'firstName' => trim($speakerData['firstName']),
                'lastName' => trim($speakerData['lastName']),
                'email' => trim($speakerData['email']),
                'title' => $speakerData['title'] ?? '',
                'company' => $speakerData['company'] ?? '',
                'bio' => $speakerData['bio'] ?? '',
                'roles' => $speakerData['roles'] ?? ['Speaker']
            ],
            'skipEmail' => $skipEmail
        ];

        if ($imageEntryId !== null) {
            $payload['imageUrlEntryId'] = $imageEntryId;
        }

        // Call EPM INTERNAL API
        $result = callEPMAPI('/epm/eventUsers/inviteUser', $payload, $eventId);
        $data = json_decode($result, true);

        if (isset($data['status']) && $data['status'] === 'ok') {
            logAudit('SPEAKER_INVITED', $speakerData['email'], [
                'eventId' => $eventId,
                'userId' => $data['userId']
            ]);

            return [
                'success' => true,
                'userId' => $data['userId'],
                'error' => null
            ];
        }

        $error = $data['error'] ?? 'Unknown error inviting speaker';
        logError("Failed to invite speaker: {$error}");

        return [
            'success' => false,
            'userId' => null,
            'error' => $error
        ];
    } catch (Exception $e) {
        logError("inviteSpeakerToEvent exception: " . $e->getMessage());
        return [
            'success' => false,
            'userId' => null,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Assign speakers to a session (agenda item)
 *
 * API Type: INTERNAL
 * Endpoint: POST /epm/sessionParticipants/addSpeakers
 *
 * @param string $eventId Event ID
 * @param string $sessionId Session ID
 * @param array $speakersArray Array of speakers: [['uid' => userId, 'order' => 1000, 'isHidden' => false], ...]
 * @return array{success: bool, error: string|null}
 */
function addSpeakersToSession(string $eventId, string $sessionId, array $speakersArray): array
{
    try {
        if (empty($sessionId)) {
            return ['success' => false, 'error' => 'sessionId is required'];
        }

        if (empty($speakersArray)) {
            return ['success' => false, 'error' => 'speakers array cannot be empty'];
        }

        // Validate speaker structure
        foreach ($speakersArray as $speaker) {
            if (!isset($speaker['uid']) || empty($speaker['uid'])) {
                return ['success' => false, 'error' => 'Each speaker must have a uid'];
            }
        }

        $payload = [
            'sessionId' => $sessionId,
            'speakers' => $speakersArray
        ];

        // Call EPM INTERNAL API
        $result = callEPMAPI('/epm/sessionParticipants/addSpeakers', $payload, $eventId);
        $data = json_decode($result, true);

        if (isset($data['status']) && $data['status'] === 'ok') {
            logAudit('SPEAKERS_ADDED_TO_SESSION', 'system', [
                'eventId' => $eventId,
                'sessionId' => $sessionId,
                'count' => count($speakersArray)
            ]);

            return ['success' => true, 'error' => null];
        }

        $error = $data['error'] ?? 'Failed to add speakers to session';
        logError("addSpeakersToSession failed: {$error}");

        return ['success' => false, 'error' => $error];
    } catch (Exception $e) {
        logError("addSpeakersToSession exception: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================================================
// IMAGE UPLOAD WORKFLOW FUNCTIONS
// ============================================================================

/**
 * Get upload credentials for speaker image (Step 1 of 2)
 *
 * API Type: INTERNAL
 * Endpoint: POST /epm/eventUsers/getUploadCredentials
 *
 * @param string $eventId Event ID
 * @param string|null $contextId Optional context ID
 * @return array{success: bool, ks?: string, uploadTokenId?: string, entryId?: string, serviceURL?: string, error?: string}
 */
function getSpeakerImageUploadCredentials(string $eventId, ?string $contextId = null): array
{
    try {
        $payload = ['contextId' => $contextId ?? ''];

        // Call EPM INTERNAL API
        $result = callEPMAPI('/epm/eventUsers/getUploadCredentials', $payload, $eventId);
        $data = json_decode($result, true);

        if (isset($data['status']) && $data['status'] === 'ok') {
            return [
                'success' => true,
                'ks' => $data['ks'],
                'uploadTokenId' => $data['uploadTokenId'],
                'entryId' => $data['entryId'],
                'serviceURL' => $data['serviceURL'],
                'error' => null
            ];
        }

        $error = $data['error'] ?? 'Failed to get upload credentials';
        logError("getSpeakerImageUploadCredentials failed: {$error}");

        return ['success' => false, 'error' => $error];
    } catch (Exception $e) {
        logError("getSpeakerImageUploadCredentials exception: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Upload image from URL using upload token (Step 2 of 2)
 *
 * API Type: PUBLIC
 * Endpoint: POST /api_v3/service/uploadtoken/action/upload
 *
 * @param string $uploadTokenId Upload token ID from credentials
 * @param string $ks Kaltura Session token from credentials
 * @param string $imageUrl URL to download image from
 * @return array{success: bool, entryId: string|null, error: string|null}
 */
function uploadImageFromURL(string $uploadTokenId, string $ks, string $imageUrl): array
{
    $tempFile = null;

    try {
        // Validate URL
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'entryId' => null, 'error' => 'Invalid image URL'];
        }

        // Download image from URL
        logDebug("Downloading image from: {$imageUrl}");
        $imageContent = @file_get_contents($imageUrl);

        if ($imageContent === false) {
            return ['success' => false, 'entryId' => null, 'error' => 'Failed to download image from URL'];
        }

        // Save to temp file
        $tempFile = sys_get_temp_dir() . '/' . uniqid('img_', true) . '.jpg';
        if (file_put_contents($tempFile, $imageContent) === false) {
            return ['success' => false, 'entryId' => null, 'error' => 'Failed to save temporary file'];
        }

        // Create CURLFile for upload
        $mimeType = mime_content_type($tempFile);
        $curlFile = new CURLFile($tempFile, $mimeType, basename($tempFile));

        $params = [
            'ks' => $ks,
            'uploadTokenId' => $uploadTokenId,
            'resume' => 'false',
            'finalChunk' => 'true',
            'resumeAt' => '0',
            'fileData' => $curlFile
        ];

        // Call Kaltura PUBLIC API
        $result = callKalturaUploadAPI('/service/uploadtoken/action/upload', $params);
        $data = json_decode($result, true);

        // Clean up temp file
        if ($tempFile && file_exists($tempFile)) {
            unlink($tempFile);
        }

        if (isset($data['attachedObjectId'])) {
            logDebug("Image uploaded successfully. Entry ID: " . $data['attachedObjectId']);
            return ['success' => true, 'entryId' => $data['attachedObjectId'], 'error' => null];
        }

        $error = $data['error'] ?? 'Upload failed';
        logError("uploadImageFromURL failed: {$error}");

        return ['success' => false, 'entryId' => null, 'error' => $error];
    } catch (Exception $e) {
        // Clean up temp file on exception
        if ($tempFile && file_exists($tempFile)) {
            @unlink($tempFile);
        }

        logError("uploadImageFromURL exception: " . $e->getMessage());
        return ['success' => false, 'entryId' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Get upload credentials for landing page images (banner, logos)
 *
 * API Type: INTERNAL
 * Endpoint: POST /epm/settings/getUploadCredentials
 *
 * @param string $eventId Event ID
 * @return array{success: bool, ks?: string, uploadTokenId?: string, entryId?: string, serviceURL?: string, error?: string}
 */
function getLandingPageImageUploadCredentials(string $eventId): array
{
    try {
        $payload = ['contextId' => ''];

        // Call EPM INTERNAL API
        $result = callEPMAPI('/epm/settings/getUploadCredentials', $payload, $eventId);
        $data = json_decode($result, true);

        if (isset($data['status']) && $data['status'] === 'ok') {
            return [
                'success' => true,
                'ks' => $data['ks'],
                'uploadTokenId' => $data['uploadTokenId'],
                'entryId' => $data['entryId'],
                'serviceURL' => $data['serviceURL'],
                'error' => null
            ];
        }

        $error = $data['error'] ?? 'Failed to get landing page upload credentials';
        logError("getLandingPageImageUploadCredentials failed: {$error}");

        return ['success' => false, 'error' => $error];
    } catch (Exception $e) {
        logError("getLandingPageImageUploadCredentials exception: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================================================
// VIDEO UPLOAD WORKFLOW FUNCTIONS
// ============================================================================

/**
 * Get upload credentials for video (SimuLive sessions) (Step 1 of 2)
 *
 * API Type: INTERNAL
 * Endpoint: POST /epm/media/getBulkMediaCredentials
 *
 * @param string $eventId Event ID
 * @param string $videoName Name for the video
 * @param string $mediaType Media type (default: 'video')
 * @return array{success: bool, ks?: string, uploadTokenId?: string, entryId?: string, serviceURL?: string, error?: string}
 */
function getVideoUploadCredentials(
    string $eventId,
    string $videoName = 'Video',
    string $mediaType = 'video'
): array {
    try {
        $payload = [
            'mediaItems' => [
                [
                    'mediaType' => $mediaType,
                    'name' => $videoName
                ]
            ]
        ];

        // Call EPM INTERNAL API
        $result = callEPMAPI('/epm/media/getBulkMediaCredentials', $payload, $eventId);
        $data = json_decode($result, true);

        if (isset($data['status']) && $data['status'] === 'ok' && !empty($data['uploadCredentials'])) {
            $creds = $data['uploadCredentials'][0];
            return [
                'success' => true,
                'ks' => $data['ks'],
                'uploadTokenId' => $creds['uploadTokenId'],
                'entryId' => $creds['entryId'],
                'serviceURL' => $data['serviceURL'],
                'error' => null
            ];
        }

        $error = $data['error'] ?? 'Failed to get video upload credentials';
        logError("getVideoUploadCredentials failed: {$error}");

        return ['success' => false, 'error' => $error];
    } catch (Exception $e) {
        logError("getVideoUploadCredentials exception: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Upload video from URL using upload token (Step 2 of 2)
 *
 * API Type: PUBLIC
 * Endpoint: POST /api_v3/service/uploadtoken/action/upload
 *
 * @param string $uploadTokenId Upload token ID from credentials
 * @param string $ks Kaltura Session token from credentials
 * @param string $videoUrl URL to download video from
 * @return array{success: bool, uploadTokenId: string|null, error: string|null}
 */
function uploadVideoFromURL(string $uploadTokenId, string $ks, string $videoUrl): array
{
    $tempFile = null;

    try {
        // Validate URL
        if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'uploadTokenId' => null, 'error' => 'Invalid video URL'];
        }

        // Download video from URL
        logDebug("Downloading video from: {$videoUrl}");
        $videoContent = @file_get_contents($videoUrl);

        if ($videoContent === false) {
            return ['success' => false, 'uploadTokenId' => null, 'error' => 'Failed to download video from URL'];
        }

        // Save to temp file
        $tempFile = sys_get_temp_dir() . '/' . uniqid('video_', true) . '.mp4';
        if (file_put_contents($tempFile, $videoContent) === false) {
            return ['success' => false, 'uploadTokenId' => null, 'error' => 'Failed to save temporary file'];
        }

        // Create CURLFile
        $curlFile = new CURLFile($tempFile, 'video/mp4', basename($tempFile));

        $params = [
            'ks' => $ks,
            'uploadTokenId' => $uploadTokenId,
            'resume' => 'false',
            'finalChunk' => 'true',
            'resumeAt' => '0',
            'fileData' => $curlFile,
            'format' => '1'
        ];

        // Call Kaltura PUBLIC API
        $result = callKalturaUploadAPI('/service/uploadtoken/action/upload', $params);
        $data = json_decode($result, true);

        // Clean up temp file
        if ($tempFile && file_exists($tempFile)) {
            unlink($tempFile);
        }

        if (isset($data['id'])) {
            logDebug("Video uploaded successfully. Upload Token ID: " . $data['id']);
            return ['success' => true, 'uploadTokenId' => $data['id'], 'error' => null];
        }

        $error = $data['error'] ?? 'Video upload failed';
        logError("uploadVideoFromURL failed: {$error}");

        return ['success' => false, 'uploadTokenId' => null, 'error' => $error];
    } catch (Exception $e) {
        // Clean up temp file on exception
        if ($tempFile && file_exists($tempFile)) {
            @unlink($tempFile);
        }

        logError("uploadVideoFromURL exception: " . $e->getMessage());
        return ['success' => false, 'uploadTokenId' => null, 'error' => $e->getMessage()];
    }
}

// ============================================================================
// LANDING PAGE FUNCTIONS (INTERNAL API)
// ============================================================================

/**
 * Get landing page configuration
 *
 * API Type: INTERNAL
 * Endpoint: POST /epm/pageBuilder/getEventPage
 *
 * @param string $eventId Event ID
 * @param string $pageId Page identifier (default: 'comingsoon')
 * @return array{success: bool, components: array|null, error: string|null}
 */
function getEventLandingPage(string $eventId, string $pageId = 'comingsoon'): array
{
    try {
        $payload = ['pageId' => $pageId];

        // Call EPM INTERNAL API
        $result = callEPMAPI('/epm/pageBuilder/getEventPage', $payload, $eventId);
        $data = json_decode($result, true);

        if (isset($data['status']) && $data['status'] === 'ok') {
            return ['success' => true, 'components' => $data['components'], 'error' => null];
        }

        $error = $data['error'] ?? 'Failed to get landing page';
        logError("getEventLandingPage failed: {$error}");

        return ['success' => false, 'components' => null, 'error' => $error];
    } catch (Exception $e) {
        logError("getEventLandingPage exception: " . $e->getMessage());
        return ['success' => false, 'components' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Update landing page configuration
 *
 * API Type: INTERNAL
 * Endpoint: POST /epm/pageBuilder/updateEventPage
 *
 * @param string $eventId Event ID
 * @param string $pageId Page identifier (e.g., 'comingsoon')
 * @param array $components Array of page components to update
 * @return array{success: bool, error: string|null}
 */
function updateEventLandingPage(string $eventId, string $pageId, array $components): array
{
    try {
        if (empty($components)) {
            return ['success' => false, 'error' => 'Components array cannot be empty'];
        }

        $payload = [
            'pageId' => $pageId,
            'components' => $components
        ];

        // Call EPM INTERNAL API
        $result = callEPMAPI('/epm/pageBuilder/updateEventPage', $payload, $eventId);
        $data = json_decode($result, true);

        if (isset($data['status']) && $data['status'] === 'ok') {
            logAudit('LANDING_PAGE_UPDATED', 'system', ['eventId' => $eventId, 'pageId' => $pageId]);
            return ['success' => true, 'error' => null];
        }

        $error = $data['error'] ?? 'Failed to update landing page';
        logError("updateEventLandingPage failed: {$error}");

        return ['success' => false, 'error' => $error];
    } catch (Exception $e) {
        logError("updateEventLandingPage exception: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================================================
// LANDING PAGE HELPER FUNCTIONS
// ============================================================================

/**
 * Update text content in a landing page component
 *
 * Finds a Text component by ID and updates its content
 *
 * @param array $components Landing page components array
 * @param string $componentId Component ID to update
 * @param string $newContent New HTML content
 * @return array Modified components array
 */
function updateLandingPageTextContent(array $components, string $componentId, string $newContent): array
{
    foreach ($components as &$component) {
        if ($component['type'] === 'Text' && $component['configuration']['id'] === $componentId) {
            $component['configuration']['content'] = $newContent;
            logDebug("Updated Text component {$componentId} with new content");
            break;
        }
    }
    return $components;
}

/**
 * Replace image in a Banner component
 *
 * Updates the Banner component with new image entry ID
 *
 * @param array $components Landing page components array
 * @param string $componentId Component ID to update
 * @param string $newEntryId New Kaltura entry ID for the image
 * @return array Modified components array
 */
function replaceBannerImage(array $components, string $componentId, string $newEntryId): array
{
    foreach ($components as &$component) {
        if ($component['type'] === 'Banner' && $component['configuration']['id'] === $componentId) {
            // Update the backgroundImageUrl to use new entry
            $component['configuration']['backgroundImageUrl'] =
                "https://cfvod.kaltura.com/p/5620062/sp/562006200/thumbnail/entry_id/{$newEntryId}/version/0";

            // Add replaceImageEntry flag for the API
            $component['configuration']['replaceImageEntry'] = $newEntryId;

            logDebug("Replaced Banner image for component {$componentId} with entry {$newEntryId}");
            break;
        }
    }
    return $components;
}

/**
 * Replace images in a TwoImages component
 *
 * Updates left and/or right images with new entry IDs
 *
 * @param array $components Landing page components array
 * @param string $componentId Component ID to update
 * @param string|null $leftEntryId New entry ID for left image (null to skip)
 * @param string|null $rightEntryId New entry ID for right image (null to skip)
 * @return array Modified components array
 */
function replaceTwoImagesComponent(
    array $components,
    string $componentId,
    ?string $leftEntryId = null,
    ?string $rightEntryId = null
): array {
    foreach ($components as &$component) {
        if ($component['type'] === 'TwoImages' && $component['configuration']['id'] === $componentId) {

            // Update left image
            if ($leftEntryId !== null) {
                $component['configuration']['leftImage']['imageUrl'] =
                    "https://cfvod.kaltura.com/p/5620062/sp/562006200/thumbnail/entry_id/{$leftEntryId}/version/0";
                $component['configuration']['leftImage']['replaceImageEntry'] = $leftEntryId;
                logDebug("Replaced left image for TwoImages component {$componentId}");
            }

            // Update right image
            if ($rightEntryId !== null) {
                $component['configuration']['rightImage']['imageUrl'] =
                    "https://cfvod.kaltura.com/p/5620062/sp/562006200/thumbnail/entry_id/{$rightEntryId}/version/0";
                $component['configuration']['rightImage']['replaceImageEntry'] = $rightEntryId;
                logDebug("Replaced right image for TwoImages component {$componentId}");
            }

            break;
        }
    }
    return $components;
}

/**
 * Find landing page component by type and ID
 *
 * @param array $components Landing page components array
 * @param string $type Component type (e.g., 'Text', 'Banner', 'TwoImages')
 * @param string $componentId Component ID
 * @return array|null Component configuration or null if not found
 */
function findLandingPageComponent(array $components, string $type, string $componentId): ?array
{
    foreach ($components as $component) {
        if ($component['type'] === $type && $component['configuration']['id'] === $componentId) {
            return $component;
        }
    }
    return null;
}

// ============================================================================
// CONVENIENCE WRAPPER FUNCTIONS (2-step workflows combined)
// ============================================================================

/**
 * Complete 2-step speaker image upload in one call
 *
 * Combines: getSpeakerImageUploadCredentials() + uploadImageFromURL()
 *
 * @param string $eventId Event ID
 * @param string $imageUrl URL to download image from
 * @param string|null $contextId Optional context ID
 * @return array{success: bool, entryId: string|null, error: string|null}
 */
function uploadSpeakerImageComplete(string $eventId, string $imageUrl, ?string $contextId = null): array
{
    // Step 1: Get credentials (INTERNAL API)
    $credResult = getSpeakerImageUploadCredentials($eventId, $contextId);

    if (!$credResult['success']) {
        return ['success' => false, 'entryId' => null, 'error' => $credResult['error']];
    }

    // Step 2: Upload image (PUBLIC API)
    $uploadResult = uploadImageFromURL($credResult['uploadTokenId'], $credResult['ks'], $imageUrl);

    if (!$uploadResult['success']) {
        return ['success' => false, 'entryId' => null, 'error' => $uploadResult['error']];
    }

    // Return the original entryId from credentials (not the upload result)
    return ['success' => true, 'entryId' => $credResult['entryId'], 'error' => null];
}

/**
 * Complete 2-step landing page image upload in one call
 *
 * Combines: getLandingPageImageUploadCredentials() + uploadImageFromURL()
 *
 * @param string $eventId Event ID
 * @param string $imageUrl URL to download image from
 * @return array{success: bool, entryId: string|null, error: string|null}
 */
function uploadLandingPageImageComplete(string $eventId, string $imageUrl): array
{
    // Step 1: Get credentials (INTERNAL API)
    $credResult = getLandingPageImageUploadCredentials($eventId);

    if (!$credResult['success']) {
        return ['success' => false, 'entryId' => null, 'error' => $credResult['error']];
    }

    // Step 2: Upload image (PUBLIC API)
    $uploadResult = uploadImageFromURL($credResult['uploadTokenId'], $credResult['ks'], $imageUrl);

    if (!$uploadResult['success']) {
        return ['success' => false, 'entryId' => null, 'error' => $uploadResult['error']];
    }

    return ['success' => true, 'entryId' => $credResult['entryId'], 'error' => null];
}

/**
 * Complete 2-step video upload in one call
 *
 * Combines: getVideoUploadCredentials() + uploadVideoFromURL()
 *
 * @param string $eventId Event ID
 * @param string $videoUrl URL to download video from
 * @param string $videoName Name for the video
 * @return array{success: bool, entryId: string|null, uploadTokenId: string|null, error: string|null}
 */
function uploadVideoComplete(string $eventId, string $videoUrl, string $videoName = 'Video'): array
{
    // Step 1: Get credentials (INTERNAL API)
    $credResult = getVideoUploadCredentials($eventId, $videoName);

    if (!$credResult['success']) {
        return [
            'success' => false,
            'entryId' => null,
            'uploadTokenId' => null,
            'error' => $credResult['error']
        ];
    }

    // Step 2: Upload video (PUBLIC API)
    $uploadResult = uploadVideoFromURL($credResult['uploadTokenId'], $credResult['ks'], $videoUrl);

    if (!$uploadResult['success']) {
        return [
            'success' => false,
            'entryId' => null,
            'uploadTokenId' => null,
            'error' => $uploadResult['error']
        ];
    }

    return [
        'success' => true,
        'entryId' => $credResult['entryId'],
        'uploadTokenId' => $uploadResult['uploadTokenId'],
        'error' => null
    ];
}
