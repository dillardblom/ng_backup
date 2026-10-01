// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later
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
