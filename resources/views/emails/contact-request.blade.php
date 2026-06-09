<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Newtown Spares Email</title>
</head>

<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f9f9f9;">
  <table width="100%" cellspacing="0" cellpadding="0" bgcolor="#f9f9f9">
    <tr>
      <td align="center">
        <table width="600" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="margin-top: 20px; border: 1px solid #e0e0e0;">

          <!-- Top Banner with Logo -->
          <tr bgcolor="#ff3d1f">
            <td align="center" style="padding: 20px;">
              <div style="background-color: white; padding: 10px 20px; display: inline-block; border-radius: 8px;">
                <img src="https://cdn11.bigcommerce.com/s-4jpol1blth/images/stencil/250x70/nts-newlogo_nts_1682517516__50628.original.png" alt="Newtown Spares Logo" width="180" style="display: block;" />
              </div>
            </td>
          </tr>

          <!-- Email Body -->
          <tr>
            <td style="padding: 30px;">
              <h2 style="color: #333333;">Hello Store Admin,</h2>
              <p style="color: #555555; font-size: 16px;">
                A new contact request has been submitted by <strong>{{ $contact->full_name }}</strong>@if($contact->company_name) from <strong>{{ $contact->company_name }}</strong>@endif.
              </p>

              <h3 style="color: #333333;">Request Details:</h3>
              <ul style="color: #555555; font-size: 16px;">
                <li><strong>Name:</strong> {{ $contact->full_name }}</li>
                <li><strong>Email:</strong> {{ $contact->email }}</li>
                <li><strong>Phone:</strong> {{ $contact->phone_number }}</li>
                <li><strong>Company:</strong> {{ $contact->company_name ?? 'N/A' }}</li>
                <li><strong>Order #:</strong> {{ $contact->order_number ?? 'N/A' }}</li>
                <li><strong>RMA #:</strong> {{ $contact->rma_number ?? 'N/A' }}</li>
                <li><strong>Comments:</strong> {{ $contact->comments ?? 'N/A' }}</li>
              </ul>

              <p style="color: #555555; font-size: 16px;">
                Please follow up with the customer as soon as possible.
              </p>

              <div style="text-align: center; margin-top: 30px;">
                <a href="https://newtownspares.com" style="background-color: #ff3d1f; color: #ffffff; padding: 12px 24px; text-decoration: none; font-weight: bold; border-radius: 4px;">Visit Store</a>
              </div>
            </td>
          </tr>

          <!-- Footer -->
          <tr bgcolor="#f0f0f0">
            <td align="center" style="font-size: 13px; color: #777777; padding: 20px;">
              &copy; 2025 Newtown Spares. All rights reserved.<br />
              <a href="https://newtownspares.com/unsubscribe" style="color: #777777;">Unsubscribe</a>
            </td>
          </tr>

          
        </table>
      </td>
    </tr>
  </table>
</body>

</html>