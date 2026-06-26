# KESA Learning Certificate System

A clean, professional certificate verification system for KESA Learning.

## 🚀 Quick Setup Guide

### Step 1: Database Configuration
1. Update `config/database.php` with your database details
2. Run `database/setup.sql` in your database

### Step 2: Upload Files
1. Upload all files to your web server
2. Ensure the `uploads/certificates/` directory has write permissions (755)

### Step 3: Admin Access
- URL: `https://yourdomain.com/admin/login.php`
- Username: `admin`
- Password: `admin123`

### Step 4: Certificate Access
- URL: `https://yourdomain.com/certificate/`
- Direct certificate: `https://yourdomain.com/certificate/?cert=CERTIFICATE_NUMBER`

## 📁 File Structure
\`\`\`
/
├── certificate/
│   └── index.php          # Main certificate verification page
├── admin/
│   ├── login.php          # Admin login
│   ├── dashboard.php      # Certificate management
│   ├── add-certificate.php # Add new certificates
│   ├── edit-certificate.php # Edit certificates
│   └── logout.php         # Logout
├── config/
│   └── database.php       # Database configuration
├── database/
│   └── setup.sql          # Database setup script
├── uploads/
│   └── certificates/      # Certificate images storage
├── .htaccess              # URL rewriting and security
└── README.md              # This file
\`\`\`

## ✨ Features
- ✅ Certificate verification by number
- ✅ Image upload and display
- ✅ Social media sharing (LinkedIn)
- ✅ Mobile responsive design
- ✅ Admin panel for certificate management
- ✅ SEO-friendly URLs
- ✅ Secure file handling

## 🔧 Requirements
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server with mod_rewrite support

## 🛡️ Security Features
- SQL injection protection
- File upload validation
- Secure admin authentication
- Protected configuration files

## 📞 Support
For support, contact the development team or check the documentation.
\`\`\`

```plaintext file="uploads/certificates/.htaccess"
# Allow image files only
<FilesMatch "\.(jpg|jpeg|png|gif|webp)$">
    Order allow,deny
    Allow from all
</FilesMatch>

# Deny everything else
<FilesMatch "^(?!.*\.(jpg|jpeg|png|gif|webp)$).*$">
    Order allow,deny
    Deny from all
</FilesMatch>
