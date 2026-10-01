<?php
// /finance/call.php
// Redirects HTTP requests to tel: scheme to bypass Telegram link limitations.

$num = preg_replace('/[^0-9+]/', '', $_GET['num'] ?? '');
if ($num !== '') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Redirecting to call...</title>
        <script>
            // Try to trigger the call prompt immediately
            window.location.href = "tel:<?php echo htmlspecialchars($num, ENT_QUOTES, 'UTF-8'); ?>";
            // If window can be closed, close it after 1.5 seconds
            setTimeout(function() {
                try {
                    window.close();
                } catch(e) {}
            }, 1500);
        </script>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                text-align: center;
                padding: 50px 20px;
                color: #333;
                background-color: #f8f9fa;
            }
            .btn {
                display: inline-block;
                padding: 12px 24px;
                margin-top: 20px;
                font-size: 16px;
                font-weight: bold;
                color: #fff;
                background-color: #007bff;
                border: none;
                border-radius: 8px;
                text-decoration: none;
                cursor: pointer;
            }
        </style>
    </head>
    <body>
        <h2>កំពុងភ្ជាប់ទៅកាន់ប្រព័ន្ធទូរស័ព្ទ...</h2>
        <p>Connecting to phone dialer...</p>
        <a href="tel:<?php echo htmlspecialchars($num, ENT_QUOTES, 'UTF-8'); ?>" class="btn">ចុចទីនេះបើមិនដំណើរការ (Click here if not redirecting)</a>
    </body>
    </html>
    <?php
    exit;
}
echo "Invalid phone number.";
