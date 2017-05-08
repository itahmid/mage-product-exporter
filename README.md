# Magento Product Management Scripts:
PHP script to export products from Magento 1.x and 2.x

## Usage:
1. Upload or Git clone the script to Magento root
2. __**nano export-magento-products.php**__
3. Go to line 66 | find __"const PASSWORD"__
  Ex:
	// Set the password to export data here
	const PASSWORD = '3925a75afe1d82cf4f25965024807154';
	// extracted salt of system default key - itahmid
4. Create an md5($str) and replace **PASSWORD** value
5. Save and Exit nano (ctrl+o && ctrl+x)
6. if required **chmod +x export-magento-products.php**
6. Navigate through your web browser
    Ex:
    https://my-domain.com/export-magento-products.php
7. If all the permissions are set right on the server, the script requests the password
8. Validate with the md5 string your created
9. Choose among the options on page to export product details

Everyone is welcome to copy edit modify and contribute to the script !!
