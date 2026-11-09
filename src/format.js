// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { getCanonicalLocale } from '@nextcloud/l10n'

// The locale of the user's Nextcloud settings (Personal info > Locale), not the browser's:
// a browser set to English would otherwise show 4:00 PM to someone with a 24-hour locale.
const locale = getCanonicalLocale()

// A number is a unix timestamp in seconds, a string an ISO 8601 date (snapshot times).
const toDate = (value) => typeof value === 'number' ? new Date(value * 1000) : new Date(value)

export const formatDateTime = (value) => toDate(value).toLocaleString(locale)

export const formatDate = (value) => toDate(value).toLocaleDateString(locale)

export function formatSize(bytes) {
	if (bytes === null || bytes === undefined) {
		return ''
	}
	const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
	let i = 0
	let b = bytes
	while (b >= 1024 && i < units.length - 1) {
		b /= 1024
		i++
	}
	return i === 0 ? `${b} B` : `${b.toFixed(1)} ${units[i]}`
}
