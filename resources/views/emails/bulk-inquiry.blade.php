<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bulk Inquiry</title>
</head>
<body>
  <h2>New Bulk Inquiry Received</h2>
  <p><strong>Full Name:</strong> {{ $inquiry->full_name }}</p>
  <p><strong>Email:</strong> {{ $inquiry->email }}</p>
  <p><strong>Phone:</strong> {{ $inquiry->phone }}</p>
  <p><strong>Comments:</strong> {{ $inquiry->comments ?? 'N/A' }}</p>
  <p><strong>Quantity:</strong> {{ $inquiry->quantity }}</p>
  <p><strong>SKU / Product Link:</strong> <a href="{{ url('/product/'.$inquiry->sku) }}">{{ $inquiry->sku }}</a></p>
</body>
</html>
