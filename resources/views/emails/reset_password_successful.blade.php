<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Password Reset Successful</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f9f9f9;">
  <table width="100%" cellspacing="0" cellpadding="0" bgcolor="#f9f9f9">
    <tr>
      <td align="center">
        <table width="600" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="margin-top: 20px; border: 1px solid #e0e0e0;">
          
          <!-- Top Banner with Visible Logo -->
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
              <p style="color: #555555; font-size: 16px;">
                Hello {{ $name }},
              </p>

              <p style="color: #555555; font-size: 16px;">
                Your password has been successfully reset.
              </p>

              <p style="color: #555555; font-size: 16px;">
                If you did not perform this action, please contact our support team immediately to secure your account.
              </p>

              <p style="color: #555555; font-size: 16px;">
                Thank you,<br />
                <strong>Newtown Spares Team</strong>
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr bgcolor="#f0f0f0">
            <td align="center" style="font-size: 13px; color: #777777; padding: 20px;">
              &copy; {{ date('Y') }} Newtown Spares. All rights reserved.<br />
              <a href="https://newtownspares.com/unsubscribe" style="color: #777777;">Unsubscribe</a>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
