import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	admin: 'src/admin.js',
}, {
	inlineCSS: false,
	extractLicenseInformation: false,
})
