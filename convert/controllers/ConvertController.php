<?php
/**
 * Xử lý các request chuyển đổi JSON ChatGPT session.
 */

require_once __DIR__ . '/../models/JsonConverterModel.php';

class ConvertController
{
    private JsonConverterModel $model;

    public function __construct()
    {
        $this->model = new JsonConverterModel();
    }

    public function handleConvert(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['json_input'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Dữ liệu không hợp lệ. Vui lòng nhập Session JSON.'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $jsonInput = (string) $data['json_input'];
            $this->model->logAction('convert', ['format' => '9router']);
            $result = $this->model->convertTo9Router($jsonInput);

            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (Throwable $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function handleCheckToken(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['token'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Token chưa được cung cấp.'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $result = $this->model->checkTokenExpiry($data['token']);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function handleCheckUsage(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['token'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Token chưa được cung cấp.'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $result = $this->model->checkTokenUsage((string) $data['token']);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function handleStats(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $stats = $this->model->getStatistics();
            echo json_encode([
                'status' => 'success',
                'statistics' => $stats
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function handleFileUpload(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Không thể tải tệp lên.'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $fileContent = file_get_contents($_FILES['file']['tmp_name']);
            if ($fileContent === false) {
                throw new RuntimeException('Không thể đọc nội dung tệp tải lên.');
            }

            $result = $this->model->convertTo9Router($fileContent);

            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (Throwable $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$controller = new ConvertController();

switch ($action) {
    case 'convert':
        $controller->handleConvert();
        break;
    case 'check_token':
        $controller->handleCheckToken();
        break;
    case 'check_usage':
        $controller->handleCheckUsage();
        break;
    case 'stats':
        $controller->handleStats();
        break;
    case 'upload':
        $controller->handleFileUpload();
        break;
    default:
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Action không được chỉ định.'
        ], JSON_UNESCAPED_UNICODE);
}
