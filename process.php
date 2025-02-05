<?php
// Azure Form Recognizer API 配置
define('AZURE_ENDPOINT', 'https://japaneast.api.cognitive.microsoft.com/vision/v3.2/read/analyze');
define('AZURE_API_KEY', '760d7f187c864ad7827555eb91a0416c');

//

// 处理文件上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipts'])) {
    $uploads_dir = __DIR__ . '/uploads/';
    if (!is_dir($uploads_dir)) {
        mkdir($uploads_dir, 0777, true);
    }

    $results = [];
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmp_name) {
        $file_name = basename($_FILES['receipts']['name'][$key]);
        $file_path = $uploads_dir . $file_name;

        if (move_uploaded_file($tmp_name, $file_path)) {
            try {
                // 调用 Azure OCR
                $ocr_result = callAzureOCR($file_path);
                
                if (!isset($ocr_result['error'])) {
                    // 解析 OCR 结果
                    $parsed_data = parseOCRResult($ocr_result);
           

                    // 写入日志
                    writeLog('ocr.log', [
                        'timestamp' => date('Y-m-d H:i:s'),
                        'filename' => $file_name,
                        'ocr_result' => $ocr_result,
                        'parsed_data' => $parsed_data
                    ]);

                    // 生成 CSV

                       // 生成 CSV - 修改 CSV 文件路径
                       $csv_filename = pathinfo($file_name, PATHINFO_FILENAME) . '.csv';
                       $csv_path = $uploads_dir . $csv_filename;
                    
                    generateCSV($parsed_data, $csv_path);

                    $results[] = [
                        'file_name' => $file_name,
                        'parsed_data' => $parsed_data,
                        'csv_path' => 'uploads/' . $csv_filename, 
                        'status' => 'success'
                    ];
               
                } else {
                    $results[] = [
                        'file_name' => $file_name,
                        'error' => $ocr_result['error'],
                        'status' => 'error'
                    ];
                }
            } catch (Exception $e) {
                $results[] = [
                    'file_name' => $file_name,
                    'error' => $e->getMessage(),
                    'status' => 'error'
                ];
            }
        }
    }

    // 显示结果
    displayResults($results);
}

// 调用 Azure OCR
function callAzureOCR($imagePath) {
    try {
        $data = file_get_contents($imagePath);
        if ($data === false) {
            throw new Exception("画像ファイルを読み込めません");
        }

        // ステップ1：分析リクエストを送信
        $ch = curl_init(AZURE_ENDPOINT);
        if ($ch === false) {
            throw new Exception("CURL初期化に失敗しました");
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Ocp-Apim-Subscription-Key: ' . AZURE_API_KEY
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new Exception("APIリクエストに失敗しました: " . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode !== 202) {
            throw new Exception("APIが予期しないステータスコードを返しました: " . $httpCode);
        }

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        curl_close($ch);

        // 操作IDを取得
        if (!preg_match('/Operation-Location:\s*(.*?)\r\n/i', $headers, $matches)) {
            throw new Exception("Operation-Locationを取得できません");
        }
        $operationLocation = trim($matches[1]);

        // ステップ2：結果を取得
        $maxRetries = 30;
        $retryInterval = 2;
        
        for ($i = 0; $i < $maxRetries; $i++) {
            sleep($retryInterval);
            
            $ch = curl_init($operationLocation);
            if ($ch === false) {
                throw new Exception("CURL初期化に失敗しました");
            }

            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    'Ocp-Apim-Subscription-Key: ' . AZURE_API_KEY
                ],
                CURLOPT_RETURNTRANSFER => true
            ]);
            
            $result = curl_exec($ch);
            if ($result === false) {
                throw new Exception("結果の取得に失敗しました: " . curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode !== 200) {
                throw new Exception("結果取得時に予期しないステータスコードが返されました: " . $httpCode);
            }

            curl_close($ch);

            $resultArray = json_decode($result, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSONの解析に失敗しました: " . json_last_error_msg());
            }

            if (isset($resultArray['status'])) {
                switch ($resultArray['status']) {
                    case 'succeeded':
                        return $resultArray;
                    case 'failed':
                        throw new Exception("分析に失敗しました: " . ($resultArray['error']['message'] ?? '不明なエラー'));
                    case 'running':
                    case 'notStarted':
                        continue 2;
                    default:
                        throw new Exception("不明なステータス: " . $resultArray['status']);
                }
            }
        }

        throw new Exception("処理がタイムアウトしました。後でもう一度お試しください");
    } catch (Exception $e) {
        writeLog('ocr_error.log', date('Y-m-d H:i:s') . ': ' . $e->getMessage());
        throw $e;
    }
}

// 解析 OCR 结果
function parseOCRResult($ocr_result) {
    $items = [];
    $total = 0;
    $lines = [];
    
    if (isset($ocr_result['analyzeResult']['readResults'][0]['lines'])) {
        $lines = $ocr_result['analyzeResult']['readResults'][0]['lines'];
    }
    
    for ($i = 0; $i < count($lines); $i++) {
        $currentLine = $lines[$i]['text'];
        
        if (shouldSkipLine($currentLine)) {
            continue;
        }
        
        if (preg_match('/¥(\d+)/', $currentLine, $priceMatch)) {
            $itemName = '';
            if ($i > 0) {
                $prevLine = $lines[$i-1]['text'];
                if (!preg_match('/¥(\d+)/', $prevLine) && 
                    strpos($prevLine, '軽') === false &&
                    strpos($prevLine, '%') === false) {
                    $itemName = trim($prevLine);
                }
            }
            
            if (!empty($itemName)) {
                $price = (int)preg_replace('/[^0-9]/', '', $priceMatch[1]);
                
                if (isValidItem($currentLine, $itemName, $price)) {
                    $items[] = [
                        'name' => $itemName,
                        'price' => $price
                    ];
                }
            }
        }
    }
    
    $total = array_sum(array_column($items, 'price'));
    
    // 使用更好的格式记录日志
    $logData = [
        'items' => $items,
        'total' => $total
    ];
    writeLog('ocr_parse.log', "解析された商品: " . json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    return [
        'items' => $items,
        'total' => $total
    ];
}




// 写入日志
function writeLog($filename, $content) {
    try {
        // BOMを追加してUTF-8で書き込む
        if (!file_exists($filename)) {
            file_put_contents($filename, "\xEF\xBB\xBF"); // UTF-8 BOM
        }

        // 配列やオブジェクトの場合はJSONに変換
        if (is_array($content) || is_object($content)) {
            $content = json_encode($content, 
                JSON_UNESCAPED_UNICODE | 
                JSON_PRETTY_PRINT | 
                JSON_UNESCAPED_SLASHES
            );
        }

        // タイムスタンプとコンテンツを整形
        $logEntry = date('Y-m-d H:i:s') . " - " . $content . PHP_EOL;
        
        // UTF-8でファイルに追記
        file_put_contents(
            $filename, 
            $logEntry, 
            FILE_APPEND | LOCK_EX
        );
        
        return true;
    } catch (Exception $e) {
        error_log('ログ書き込みエラー: ' . $e->getMessage());
        return false;
    }
}

// 创建一个专门处理CSV下载的函数
function downloadCSV($csv_path) {
    if (file_exists($csv_path)) {
        // 设置合适的头信息
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . basename($csv_path) . '"');
        header('Content-Length: ' . filesize($csv_path));
        header('Pragma: no-cache');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');
        
        // 输出文件内容
        readfile($csv_path);
        exit;
    }
}

// 添加一个处理下载请求的部分
if (isset($_GET['download']) && !empty($_GET['file'])) {
    $file = $_GET['file'];
    // 安全检查：确保文件路径在uploads目录下
    $csv_path = __DIR__ . '/uploads/' . basename($file);
    downloadCSV($csv_path);
}

function displayResults($results) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>レシート処理結果</title>
        <style>
    /* 重设基础样式 */
    body {
        font-family: , sans-serif;
        background: radial-gradient(circle, #f0f0f0, #dfe6e9);
        margin: 0;
        padding: 20px;
        color: #444;
    }

    h1 {
        text-align: center;
        color: #1abc9c;
        font-size: 2.5em;
        margin-bottom: 30px;
        font-weight: 700;
    }

    /* 结果容器 */
    .result-container {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        padding: 30px;
        max-width: 900px;
        margin: 20px auto;
        border-left: 10px solid #3498db;
        transform: translateY(5px);
        transition: transform 0.3s ease;
    }

    .result-container:hover {
        transform: translateY(-5px);
    }

    /* 文件名称样式 */
    .file-name {
        font-size: 1.4em;
        font-weight: 600;
        color: #2980b9;
        margin-bottom: 20px;
        padding-bottom: 5px;
        border-bottom: 2px solid #ecf0f1;
    }

    /* 项目列表 */
    .items-list {
        margin: 20px 0;
    }

    /* 单个项目 */
    .item {
        background-color: #ecf0f1;
        padding: 12px;
        margin-bottom: 15px;
        border-radius: 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .item-name {
        flex-grow: 1;
        font-size: 1.1em;
        color: #34495e;
        font-weight: 500;
    }

    .item-price {
        font-size: 1.2em;
        color: #16a085;
        font-weight: 700;
        min-width: 100px;
        text-align: right;
    }

    /* 总计 */
    .total {
        margin-top: 20px;
        padding-top: 15px;
        font-size: 1.3em;
        font-weight: 600;
        color: #e74c3c;
        border-top: 3px dashed #f2f2f2;
        text-align: right;
    }

    /* 下载链接 */
    .download-links {
        margin-top: 30px;
        text-align: center;
    }

    .download-link {
        display: inline-block;
        padding: 12px 25px;
        margin: 5px;
        background-color: #3498db;
        color: white;
        font-weight: 600;
        text-decoration: none;
        border-radius: 6px;
        transition: background-color 0.3s ease, transform 0.2s ease;
    }

    .download-link:hover {
        background-color: #2980b9;
        transform: scale(1.05);
    }

    /* 返回按钮 */
    .back-button {
        display: block;
        width: 230px;
        margin: 40px auto;
        padding: 12px 20px;
        background-color: #2ecc71;
        color: white;
        text-decoration: none;
        font-weight: 600;
        text-align: center;
        border-radius: 6px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        transition: background-color 0.3s, transform 0.2s;
    }

    .back-button:hover {
        background-color: #27ae60;
        transform: scale(1.05);
    }

    /* 错误信息 */
    .error-message {
        background-color: #ffebeb;
        border-left: 6px solid #e74c3c;
        color: #e74c3c;
        padding: 15px;
        margin: 20px 0;
        border-radius: 8px;
        font-weight: 500;
    }

    /* 响应式设计 */
    @media (max-width: 768px) {
        body {
            padding: 10px;
        }

        .result-container {
            padding: 20px;
        }

        .item {
            flex-direction: column;
            align-items: flex-start;
        }

        .item-price {
            text-align: left;
            padding-top: 5px;
        }
    }
</style>

        <script>
            window.onload = function() {
                if (window.opener && !window.opener.closed) {
                    var loadingOverlay = window.opener.document.getElementById("loadingOverlay");
                    if (loadingOverlay) {
                        loadingOverlay.style.display = "none";
                    }
                }
            };
        </script>
    </head>
    <body>
        <h1>🧾 解析結果レポート</h1>';

    foreach ($results as $result) {
        echo "<div class='result-container'>";
        echo "<div class='file-name'>📂ファイル: {$result['file_name']}</div>";

        if (isset($result['error'])) {
            // エラーがある場合
            echo "<div class='error-message'>エラー: {$result['error']}</div>";
        } elseif (isset($result['parsed_data']) && is_array($result['parsed_data'])) {
            // 正常に処理された場合
            echo "<div class='items-list'>";
            
            if (isset($result['parsed_data']['items']) && is_array($result['parsed_data']['items'])) {
                foreach ($result['parsed_data']['items'] as $item) {
                    echo "<div class='item'>";
                    echo "<span class='item-name'>" . htmlspecialchars($item['name']) . "</span>";
                    echo "<span class='item-price'>¥" . number_format($item['price']) . "</span>";
                    echo "</div>";
                }
            } else {
                echo "<div class='error-message'>商品データが見つかりません。</div>";
            }
            
            echo "</div>";
            
            if (isset($result['parsed_data']['total'])) {
                echo "<div class='total'>💰 合計金額: ¥" . number_format($result['parsed_data']['total']) . "</div>";
            }
            
            echo "<div class='download-links'>";
            if (isset($result['csv_path']) && file_exists($result['csv_path'])) {
                echo "<a href='process.php?download=1&file=" . urlencode(basename($result['csv_path'])) . "' class='download-link'>📥 CSVダウンロード</a>";
            }
            if (file_exists('ocr.log')) {
                echo "<a href='ocr.log' class='download-link' download> 📄 ログファイル取得</a>";
            }
            echo "</div>";
        } else {
            echo "<div class='error-message'>データの処理中にエラーが発生しました。</div>";
        }
        
        echo "</div>";
    }

    echo '<a href="index.html" class="back-button">戻る</a>';
    echo '</body></html>';
}

// 生成CSV文件的函数保持不变
function generateCSV($parsed_data, $csv_path) {
    try {
        // 构建格式化的字符串
        $items_str = [];
        foreach ($parsed_data['items'] as $item) {
            $items_str[] = sprintf("%s　¥%d", $item['name'], $item['price']);
        }
        
        // 添加合计
        $items_str[] = sprintf("合計　¥%d", $parsed_data['total']);
        
        // 将所有项目用逗号和空格连接
        $output_str = implode(', ', $items_str);
        
        // 写入CSV文件
        $fp = fopen($csv_path, 'w');
        if ($fp === false) {
            throw new Exception("无法创建CSV文件");
        }
        
        // 设置CSV文件的编码为UTF-8 BOM，以确保Excel正确显示日文
        fwrite($fp, "\xEF\xBB\xBF");
        
        // 写入数据
        fwrite($fp, $output_str);
        
        // 关闭文件
        fclose($fp);
        
        return true;
    } catch (Exception $e) {
        writeLog('csv_error.log', date('Y-m-d H:i:s') . ': ' . $e->getMessage());
        return false;
    }
}

// 添加错误处理包装器
function processReceipt($file_path) {
    try {
        $ocr_result = callAzureOCR($file_path);
        return parseOCRResult($ocr_result);
    } catch (Exception $e) {
        writeLog('process_error.log', sprintf(
            "处理失败 - 文件: %s, 错误: %s",
            $file_path,
            $e->getMessage()
        ));
        return [
            'items' => [],
            'total' => 0,
            'error' => $e->getMessage()
        ];
    }
}

// ヘルパー関数
function shouldSkipLine($line) {
    $skipPatterns = [
        'FamilyMart',
        '店',
        '電話',
        '登録',
        'レジ',
        '責No',
        '領',
        '-',
        '内消費税',
        '交通系マネー',
        '軽減税率'
    ];
    
    foreach ($skipPatterns as $pattern) {
        if (strpos($line, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

function isValidItem($line, $name, $price) {
    return strpos($line, '税') === false && 
           strpos($line, '合計') === false && 
           strpos($name, '税') === false &&
           strpos($name, '残高') === false &&
           strpos($name, 'マネー') === false &&
           strpos($name, '合') === false &&
           strpos($name, '3') === false &&
           $price > 0;
}
