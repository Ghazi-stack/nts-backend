<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Invoice</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            margin: 0;
            padding: 30px;
            color: #333;
            font-size: 14px;
        }
        .invoice-container {
            max-width: 700px;
            margin: auto;
            border: 1px solid #ccc;
            padding: 25px;
        }
        h2 {
            text-align: center;
            color: #8B3EFF;
            margin-bottom: 20px;
        }
        .info-table {
            width: 100%;
            margin-bottom: 30px;
            border-collapse: collapse;
        }
        .info-table td {
            padding: 8px;
            border-bottom: 1px solid #eee;
        }
        .label {
            font-weight: bold;
            width: 150px;
        }
        .footer {
            text-align: center;
            font-size: 12px;
            color: #999;
            margin-top: 50px;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <h2>Payment Invoice</h2>

        <table class="info-table">
            <tr>
                <td class="label">Payment ID:</td>
                <td>{{ $payment->id }}</td>
            </tr>
            <tr>
                <td class="label">Amount:</td>
                <td>${{ number_format($payment->amount, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Status:</td>
                <td>{{ ucfirst($payment->payment_status) }}</td>
            </tr>
            <tr>
                <td class="label">Date:</td>
                <td>{{ $payment->created_at->format('d M Y') }}</td>
            </tr>
            @if ($payment->method)
            <tr>
                <td class="label">Payment Method:</td>
                <td>{{ ucfirst($payment->method) }}</td>
            </tr>
            @endif
        </table>

        <div class="footer">
            This is a system-generated invoice. No signature is required.<br>
            Thank you for your business!
        </div>
    </div>
</body>
</html>
