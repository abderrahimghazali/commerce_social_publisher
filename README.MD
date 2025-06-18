## Installation Instructions:

1. Place the module in `modules/custom/commerce_social_publisher/`
2. Enable the module: `drush en commerce_social_publisher`
3. Configure API credentials at `/admin/commerce/config/social-publisher`
4. Grant "publish to social media" permission to appropriate user roles
5. Visit any Commerce Product page to see the new "Share" tab

## Features:

- ✅ Adds "Share" tab to Commerce Product pages
- ✅ Form with platform selection (Facebook, Instagram)
- ✅ Image upload with fallback to product image
- ✅ Custom message with token support
- ✅ Scheduling functionality
- ✅ Queue-based processing with cron
- ✅ Proper permissions and access control
- ✅ Database storage for post tracking
- ✅ Meta Graph API integration
- ✅ Error handling and logging
- ✅ Admin configuration form

## Next Steps:

1. Create the settings form for API configuration
2. Add OAuth flow for Facebook/Instagram authentication
3. Implement retry mechanism for failed posts
4. Add post status dashboard
5. Extend to support more platforms (Twitter, LinkedIn, etc.)
