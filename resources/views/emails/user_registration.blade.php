<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Welcome to Newtown Spares</title>
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
              <h2 style="color: #333333;">Hi {{ $user->name }}</h2>
              <p style="color: #555555; font-size: 16px;">
                Thanks for registering with <strong>Newtown Spares</strong>!
              </p>
              <p style="color: #555555; font-size: 16px;">
                We’re excited to have you on board. From high-quality server solutions to tech components, we’re here to elevate your digital game.
              </p>
              <p style="color: #555555; font-size: 16px;">
                If you ever need support or have questions, feel free to contact our team — we’re happy to help.
              </p>

              <div style="text-align: center; margin-top: 30px;">
                <a href="https://www.newtownspares.com" style="background-color: #ff3d1f; color: #ffffff; padding: 12px 24px; text-decoration: none; font-weight: bold; border-radius: 4px;">Visit Our Store</a>
              </div>
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
