<?php
/**
 * Расширенный обработчик заявок для Bitrix24 CRM
 * Поддерживает пользовательские поля для данных калькулятора
 * 
 * Версия: 2.0
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Конфигурация
define('BITRIX24_WEBHOOK', '');
define('LOG_FILE', __DIR__ . '/bitrix24_logs.txt');
define('LOG_ENABLED', true);
define('USE_CUSTOM_FIELDS', true); 


const CUSTOM_FIELDS = [
    'loan_amount' => 'UF_CRM_1762440594133',          // Сумма кредита (руб.)
    'loan_term' => 'UF_CRM_1762440608882',              // Срок кредита (месяцы)
    'interest_rate' => 'UF_CRM_1762440622198',      // Процентная ставка (%)
    'payment_type' => 'UF_CRM_1762440644083',        // Тип платежа (annuity/differentiated)
    'monthly_payment' => 'UF_CRM_1762440657381',  // Ежемесячный платёж (руб.)
    'total_payment' => 'UF_CRM_1762440672565',      // Общая сумма выплат (руб.)
    'overpayment' => 'UF_CRM_1762440678131'           // Переплата по кредиту (руб.)
];

/**
 * Логирование
 */
function writeLog($message, $level = 'INFO') {
    if (!LOG_ENABLED) return;
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

/**
 * Запрос к Bitrix24 REST API
 */
function bitrix24Request($method, $params = []) {
    $url = BITRIX24_WEBHOOK . $method . '.json';
    
    writeLog("Request to $method: " . json_encode($params, JSON_UNESCAPED_UNICODE));
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        writeLog("CURL Error: $error", 'ERROR');
        return ['error' => $error, 'error_description' => 'CURL request failed'];
    }
    
    if ($httpCode !== 200) {
        writeLog("HTTP Error: Code $httpCode", 'ERROR');
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        writeLog("JSON decode error: " . json_last_error_msg(), 'ERROR');
        return ['error' => 'invalid_response', 'error_description' => 'Invalid JSON response'];
    }
    
    writeLog("Response from $method: " . json_encode($result, JSON_UNESCAPED_UNICODE));
    
    return $result;
}

/**
 * Нормализация телефона
 */
function normalizePhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Если начинается с 8, заменяем на 7
    if (substr($phone, 0, 1) === '8') {
        $phone = '7' . substr($phone, 1);
    }
    
    // Добавляем +7 если нет
    if (substr($phone, 0, 1) !== '7') {
        $phone = '7' . $phone;
    }
    
    return '+' . $phone;
}

/**
 * Поиск контакта по телефону
 */
function findContactByPhone($phone) {
    $normalizedPhone = normalizePhone($phone);
    
    writeLog("Searching contact by phone: $normalizedPhone");
    
    $result = bitrix24Request('crm.contact.list', [
        'filter' => ['PHONE' => $normalizedPhone],
        'select' => ['ID', 'NAME', 'PHONE']
    ]);
    
    if (!empty($result['result']) && count($result['result']) > 0) {
        $contactId = $result['result'][0]['ID'];
        writeLog("Found existing contact: ID=$contactId");
        return $contactId;
    }
    
    writeLog("Contact not found");
    return null;
}

/**
 * Создание или обновление контакта
 */
function createOrUpdateContact($name, $phone, $additionalData = []) {
    $normalizedPhone = normalizePhone($phone);
    
    // Ищем существующий контакт
    $contactId = findContactByPhone($phone);
    
    if ($contactId) {
        // Обновляем существующий контакт
        writeLog("Updating existing contact: ID=$contactId");
        
        $updateFields = [
            'NAME' => $name
        ];
        
        if (!empty($additionalData['utm_source'])) {
            $updateFields['UTM_SOURCE'] = $additionalData['utm_source'];
        }
        
        $result = bitrix24Request('crm.contact.update', [
            'id' => $contactId,
            'fields' => $updateFields
        ]);
        
        return $contactId;
    }
    
    // Создаём новый контакт
    writeLog("Creating new contact: Name=$name, Phone=$normalizedPhone");
    
    $fields = [
        'NAME' => $name,
        'PHONE' => [
            ['VALUE' => $normalizedPhone, 'VALUE_TYPE' => 'WORK']
        ],
        'SOURCE_ID' => 'WEB',
        'OPENED' => 'Y',
        'TYPE_ID' => 'CLIENT'
    ];
    
    // UTM метки
    if (!empty($additionalData['utm_source'])) {
        $fields['UTM_SOURCE'] = $additionalData['utm_source'];
    }
    if (!empty($additionalData['utm_medium'])) {
        $fields['UTM_MEDIUM'] = $additionalData['utm_medium'];
    }
    if (!empty($additionalData['utm_campaign'])) {
        $fields['UTM_CAMPAIGN'] = $additionalData['utm_campaign'];
    }
    
    $result = bitrix24Request('crm.contact.add', ['fields' => $fields]);
    
    if (isset($result['result'])) {
        writeLog("Contact created successfully: ID=" . $result['result']);
        return $result['result'];
    }
    
    writeLog("Failed to create contact: " . json_encode($result), 'ERROR');
    return null;
}

/**
 * Формирование комментария со всеми данными
 */
function buildDealComment($leadSource, $additionalData) {
    $comment = "Источник заявки: $leadSource\n\n";
    
    // UTM метки
    $utmLabels = [];
    if (!empty($additionalData['utm_source'])) $utmLabels[] = "Source: {$additionalData['utm_source']}";
    if (!empty($additionalData['utm_medium'])) $utmLabels[] = "Medium: {$additionalData['utm_medium']}";
    if (!empty($additionalData['utm_campaign'])) $utmLabels[] = "Campaign: {$additionalData['utm_campaign']}";
    if (!empty($additionalData['utm_content'])) $utmLabels[] = "Content: {$additionalData['utm_content']}";
    if (!empty($additionalData['utm_term'])) $utmLabels[] = "Term: {$additionalData['utm_term']}";
    
    if (!empty($utmLabels)) {
        $comment .= "=== UTM МЕТКИ ===\n";
        $comment .= implode("\n", $utmLabels) . "\n";
    }
    
    return $comment;
}

/**
 * Создание сделки
 */
function createDeal($title, $contactId, $leadSource, $additionalData = []) {
    writeLog("Creating deal: Title='$title', ContactID=$contactId, Source=$leadSource");
    
    $fields = [
        'TITLE' => $title,
        'CONTACT_ID' => $contactId,
        'OPENED' => 'Y',
        'SOURCE_ID' => 'WEB',
        'STAGE_ID' => 'NEW', // Начальная стадия
    ];
    
    // Сумма сделки
    if (!empty($additionalData['loan_amount'])) {
        $fields['OPPORTUNITY'] = $additionalData['loan_amount'];
        $fields['CURRENCY_ID'] = 'RUB';
    }
    
    // Пользовательские поля (если включены)
    if (USE_CUSTOM_FIELDS) {
        // Обрабатываем каждое поле отдельно
        if (!empty($additionalData['loan_amount'])) {
            $fields[CUSTOM_FIELDS['loan_amount']] = $additionalData['loan_amount'];
        }
        if (!empty($additionalData['loan_term'])) {
            $fields[CUSTOM_FIELDS['loan_term']] = $additionalData['loan_term'];
        }
        if (!empty($additionalData['interest_rate'])) {
            $fields[CUSTOM_FIELDS['interest_rate']] = $additionalData['interest_rate'];
        }
        if (!empty($additionalData['payment_type'])) {
            // Для типа платежа создаем массив
            $fields[CUSTOM_FIELDS['payment_type']] = [$additionalData['payment_type'] === 'annuity' ? 45 : 47];
        }
        if (!empty($additionalData['monthly_payment'])) {
            $fields[CUSTOM_FIELDS['monthly_payment']] = $additionalData['monthly_payment'];
        }
        if (!empty($additionalData['total_payment'])) {
            $fields[CUSTOM_FIELDS['total_payment']] = $additionalData['total_payment'];
        }
        if (!empty($additionalData['overpayment'])) {
            $fields[CUSTOM_FIELDS['overpayment']] = $additionalData['overpayment'];
        }
    }
    
    // Комментарий со всеми данными
    $fields['COMMENTS'] = buildDealComment($leadSource, $additionalData);
    
    // UTM метки (стандартные поля Bitrix24)
    if (!empty($additionalData['utm_source'])) {
        $fields['UTM_SOURCE'] = $additionalData['utm_source'];
    }
    if (!empty($additionalData['utm_medium'])) {
        $fields['UTM_MEDIUM'] = $additionalData['utm_medium'];
    }
    if (!empty($additionalData['utm_campaign'])) {
        $fields['UTM_CAMPAIGN'] = $additionalData['utm_campaign'];
    }
    if (!empty($additionalData['utm_content'])) {
        $fields['UTM_CONTENT'] = $additionalData['utm_content'];
    }
    if (!empty($additionalData['utm_term'])) {
        $fields['UTM_TERM'] = $additionalData['utm_term'];
    }
    
    $result = bitrix24Request('crm.deal.add', ['fields' => $fields]);
    
    if (isset($result['result'])) {
        writeLog("Deal created successfully: ID=" . $result['result']);
        return $result['result'];
    }
    
    writeLog("Failed to create deal: " . json_encode($result), 'ERROR');
    return null;
}

/**
 * Создание задачи для менеджера (опционально)
 */
function createTask($dealId, $contactName, $phone) {
    $taskTitle = "Связаться с клиентом: $contactName";
    $taskDescription = "Клиент оставил заявку на сайте.\n";
    $taskDescription .= "Телефон: $phone\n";
    $taskDescription .= "Сделка: [URL=/crm/deal/details/$dealId/]Открыть сделку[/URL]";
    
    $fields = [
        'TITLE' => $taskTitle,
        'DESCRIPTION' => $taskDescription,
        'RESPONSIBLE_ID' => 1, // ID ответственного (измените на нужного)
        'DEADLINE' => date('c', strtotime('+1 hour')),
        'UF_CRM_TASK' => ['D_' . $dealId], // Привязка к сделке
    ];
    
    $result = bitrix24Request('tasks.task.add', ['fields' => $fields]);
    
    if (isset($result['result']['task']['id'])) {
        writeLog("Task created: ID=" . $result['result']['task']['id']);
        return $result['result']['task']['id'];
    }
    
    return null;
}

// ==================== ОСНОВНАЯ ЛОГИКА ====================

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }
    
    // Получение данных
    $inputData = file_get_contents('php://input');
    writeLog("=== NEW REQUEST ===");
    writeLog("Raw input: $inputData");
    
    $data = json_decode($inputData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $data = $_POST;
        writeLog("Using POST data instead of JSON");
    }
    
    if (empty($data)) {
        throw new Exception('Нет данных для обработки');
    }
    
    writeLog("Parsed data: " . json_encode($data, JSON_UNESCAPED_UNICODE));
    
    // Валидация
    if (empty($data['name']) || empty($data['phone'])) {
        throw new Exception('Не указаны обязательные поля: имя и телефон');
    }
    
    $name = trim($data['name']);
    $phone = normalizePhone(trim($data['phone']));
    $leadSource = !empty($data['lead_source']) ? $data['lead_source'] : 'website_form';
    
    writeLog("Processing: Name='$name', Phone='$phone', Source='$leadSource'");
    
    // Создание/обновление контакта
    $contactId = createOrUpdateContact($name, $phone, $data);
    
    if (!$contactId) {
        throw new Exception('Не удалось создать/обновить контакт');
    }
    
    // Формирование названия сделки
    $dealTitle = match($leadSource) {
        'calculator_modal', 'calculator' => "Заявка из калькулятора: $name",
        'callback_form' => "Обратный звонок: $name",
        default => "Заявка с сайта: $name"
    };
    
    // Создание сделки
    $dealId = createDeal($dealTitle, $contactId, $leadSource, $data);
    
    if (!$dealId) {
        throw new Exception('Не удалось создать сделку');
    }
    
    // Опционально: создание задачи для менеджера
    // $taskId = createTask($dealId, $name, $phone);
    
    // Успешный ответ
    $response = [
        'success' => true,
        'contact_id' => $contactId,
        'deal_id' => $dealId,
        'message' => 'Заявка успешно отправлена',
        'data' => [
            'contact_url' => "https://b24hrms.bitrix24.ru/crm/contact/details/$contactId/",
            'deal_url' => "https://b24hrms.bitrix24.ru/crm/deal/details/$dealId/"
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    writeLog("=== SUCCESS === ContactID=$contactId, DealID=$dealId");
    
} catch (Exception $e) {
    http_response_code(400);
    
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('c')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    writeLog("=== ERROR === " . $e->getMessage(), 'ERROR');
}
