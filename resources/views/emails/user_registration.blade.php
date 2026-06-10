<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <meta name="viewport" content="width=device-width">

  <style type="text/css">
    @media only screen {
      html {
        background: #fff;
        min-height: 100%;
      }
    }

    body,
    table,
    tr,
    td,
    th,
    div,
    h1,
    p,
    img {
      Margin: 0;
      margin: 0;
      padding: 0;
    }

    body {
      -moz-box-sizing: border-box;
      -webkit-box-sizing: border-box;
      box-sizing: border-box;
      min-width: 100%;
      -ms-text-size-adjust: 100%;
      -webkit-text-size-adjust: 100%;
      width: 100%;
    }

    table {
      border-collapse: collapse;
      border-spacing: 0;
      width: 100%;
    }

    td,
    th {
      border-collapse: collapse;
    }

    h1,
    p,
    a,
    span,
    img {
      color: #333;
      font-family: Montserrat, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      font-size: 14px;
      font-weight: 400;
      line-height: 1.57143;
      word-wrap: break-word;
    }

    h1 {
      font-size: 28px;
      font-weight: 500;
      line-height: 1.21429;
    }

    h1:not(:last-child),
    p:not(:last-child) {
      Margin: 0 0 32px;
      margin: 0 0 32px;
    }

    a {
      color: #2199e8;
      display: inline-block;
      text-decoration: none;
    }

    strong {
      font-weight: 600;
    }

    img {
      border: none;
      display: block;
      outline: 0;
      text-align: center;
      max-width: 100%;
      max-height: 100%;
    }

    hr {
      border: none;
      border-bottom: 1px solid #d0d0d0;
    }

    .container {
      Margin: 0 auto;
      margin: 0 auto;
      width: 544px;
    }

    .column {
      Margin: 0 auto;
      margin: 0 auto;
      width: 544px;
    }

    .column td,
    .column th {
      text-align: left;
      vertical-align: top;
    }

    .expander {
      padding: 0 !important;
      visibility: hidden;
      width: 0;
    }

    .spacer--16 td {
      font-size: 16px;
      height: 16px;
      line-height: 16px;
    }

    .spacer--24 td {
      font-size: 24px;
      height: 24px;
      line-height: 24px;
    }

    .spacer--32 td {
      font-size: 32px;
      height: 32px;
      line-height: 32px;
    }

    .spacer--40 td {
      font-size: 40px;
      height: 40px;
      line-height: 40px;
    }

    .delimiter th {
      vertical-align: middle;
    }

    .delimiter__image,
    .delimiter__image-block {
      width: 48px;
    }

    .logo td {
      padding: 24px 0;
    }

    .logo a {
      color: #333;
      display: block;
      text-align: center;
    }

    .logo img {
      color: #333;
      text-decoration: none;
      margin: 0 auto;
    }

    .logo span {
      color: #333;
      font-size: 36px;
      font-weight: 600;
    }

    h2 {
      Margin: 0 0 4px;
      color: #333;
      font-family: Montserrat, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      font-size: 21px;
      font-weight: 500;
      line-height: 1.57143;
      margin: 0 0 4px;
      word-wrap: break-word;
    }

    .details {
      border-top: 1px solid #e5e5e5;
    }

    .details>tr>th {
      padding-top: 16px;
    }

    .details__content {
      width: auto;
    }

    .details__content tr:not(:last-child) th {
      padding-bottom: 8px;
    }

    .details__content th:first-child {
      padding-right: 30px;
    }

    .sigh-in {
      border: 1px solid #999;
      border-radius: 4px;
      box-sizing: border-box;
      color: #777;
      display: inline-block;
      font-weight: 600;
      padding: 4px 15px;
      text-align: center;
      white-space: nowrap;
    }

    .store p {
      Margin: 0;
      margin: 0;
    }

    .store a {
      color: #777;
      font-size: 12px;
    }

    .store-button a {
      border: 1px solid #999;
      border-radius: 4px;
      box-sizing: border-box;
      color: #777;
      padding: 3px 15px;
      text-align: center;
      white-space: nowrap;
      width: 100%;
    }

    @media only screen and (max-width: 599px) {
      img {
        height: auto !important;
        width: auto !important;
      }

      .container {
        width: 100% !important;
      }

      .column {
        box-sizing: border-box;
        height: auto !important;
        padding-left: 15px !important;
        padding-right: 15px !important;
      }
    }
  </style>
</head>

<body>
  <table class="body" style="background: #fff; height: 100%; width: 100%;">
    <tr>
      <td style="text-align: center;">
        <table class="spacer spacer--16">
          <tr>
            <td>&nbsp;</td>
          </tr>
        </table>

        <table class="container">
          <tr>
            <td>
              <!-- Logo -->
              <table class="row">
                <tr>
                  <th class="column">
                    <table class="logo">
                      <tr>
                        <th style="text-align: center;">
                          <a href="{{ $store?->base_url ?? '#' }}" target="_blank">
                            @if($logo && $logo->logo_url)
                            <img src="{{ $logo->logo_url }}" alt="{{ $store?->name }} Logo" width="200" style="display: block; margin: 0 auto;">
                            @else
                            <span style="font-size: 36px; font-weight: 600; color: #333;">{{ $store?->name ?? 'Our Store' }}</span>
                            @endif
                          </a>
                        </th>
                        <th class="expander"></th>
                      </tr>
                    </table>
                  </th>
                </tr>
              </table>

              <!-- Delimiter -->
              <table class="row">
                <tr>
                  <th class="column">
                    <table>
                      <tr>
                        <th>
                          <hr style="border: none; border-bottom: 1px solid #d0d0d0;">
                        </th>
                        <th class="expander"></th>
                      </tr>
                    </table>
                  </th>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <table class="spacer spacer--40">
          <tr>
            <td>&nbsp;</td>
          </tr>
        </table>

        <table class="container">
          <tr>
            <td>
              <!-- Welcome Message -->
              <table class="row">
                <tr>
                  <th class="column">
                    <table>
                      <tr>
                        <th>
                          <h1>Welcome to {{ $store?->name ?? 'Our Store' }}!</h1>
                          <p>
                            Hi {{ $user->name }},
                            <br />
                            Thanks for creating an account with {{ $store?->name ?? 'our store' }}. We're excited to have you on board!
                          </p>
                        </th>
                        <th class="expander"></th>
                      </tr>
                    </table>
                  </th>
                </tr>
              </table>

              <table class="spacer spacer--32">
                <tr>
                  <td>&nbsp;</td>
                </tr>
              </table>

              <!-- Account Details -->
              <table class="row">
                <tr>
                  <th class="column">
                    <table>
                      <tr>
                        <th>
                          <h2>Your Account Details</h2>

                          <table class="details">
                            <tr>
                              <th>
                                <table class="details__content">
                                  <tr>
                                    <th class="details__first-column">
                                      <p>Email</p>
                                    </th>
                                    <th>
                                      <a href="mailto:{{ $user->email }}" target="_blank">
                                        <strong>{{ $user->email }}</strong>
                                      </a>
                                    </th>
                                  </tr>
                                  <tr>
                                    <th class="details__first-column">
                                      <p>Password</p>
                                    </th>
                                    <th>
                                      <p>
                                        <strong>The password you provided during registration</strong>
                                      </p>
                                    </th>
                                  </tr>
                                </table>
                              </th>
                            </tr>
                          </table>
                        </th>
                        <th class="expander"></th>
                      </tr>
                    </table>
                  </th>
                </tr>
              </table>

              <table class="spacer spacer--16">
                <tr>
                  <td>&nbsp;</td>
                </tr>
              </table>

              <!-- Sign In Button -->
              <table class="row">
                <tr>
                  <th class="column">
                    <table>
                      <tr>
                        <th>
                          <a href="{{ $store?->base_url ?? '#' }}" class="sigh-in">Sign In</a>
                        </th>
                        <th class="expander"></th>
                      </tr>
                    </table>
                  </th>
                </tr>
              </table>

              <table class="spacer spacer--32">
                <tr>
                  <td>&nbsp;</td>
                </tr>
              </table>

              <!-- Help Text -->
              <table class="row">
                <tr>
                  <th class="column">
                    <table>
                      <tr>
                        <th>
                          <p>If you have any questions or need help, feel free to contact our support team. We're here to help!</p>
                        </th>
                        <th class="expander"></th>
                      </tr>
                    </table>
                  </th>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <table class="spacer spacer--40">
          <tr>
            <td>&nbsp;</td>
          </tr>
        </table>

        <!-- Footer -->
        <table class="container">
          <tr>
            <td>
              <table class="row">
                <tr>
                  <th class="column">
                    <table>
                      <tr>
                        <th>
                          <hr style="border: none; border-bottom: 1px solid #d0d0d0;">
                        </th>
                        <th class="expander"></th>
                      </tr>
                    </table>
                  </th>
                </tr>
              </table>

              <table class="spacer spacer--24">
                <tr>
                  <td>&nbsp;</td>
                </tr>
              </table>

              <table class="row">
                <tr>
                  <th class="column" style="text-align: center;">
                    <table>
                      <tr>
                        <th style="text-align: center;">
                          <p style="margin: 0;"><strong>{{ $store?->name ?? 'Our Store' }}</strong></p>
                          <p style="margin: 0;">
                            <a href="{{ $store?->base_url ?? '#' }}" target="_blank" style="color: #777; font-size: 12px;">{{ $store?->base_url ?? '' }}</a>
                          </p>
                          <p style="margin-top: 10px; color: #999; font-size: 12px;">&copy; {{ date('Y') }} {{ $store?->name ?? 'Our Store' }}. All rights reserved.</p>
                        </th>
                        <th class="expander"></th>
                      </tr>
                    </table>
                  </th>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <table class="spacer spacer--16">
          <tr>
            <td>&nbsp;</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>

</html>