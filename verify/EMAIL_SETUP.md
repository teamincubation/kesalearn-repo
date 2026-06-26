# Email Configuration for Password Reset

## Hostinger Email Setup

### 1. Create Email Account
1. Go to Hostinger control panel
2. Navigate to "Email Accounts"
3. Create a new email: `noreply@kesalearn.com`
4. Set a strong password

### 2. Configure SMTP Settings
Update `config/database.php` with these settings:

```php
$smtp_host = 'smtp.hostinger.com';
$smtp_port = 587;
$smtp_username = 'noreply@kesalearn.com';
$smtp_password = 'your_email_password';
$from_email = 'noreply@kesalearn.com';
$from_name = 'KESA Learning';
