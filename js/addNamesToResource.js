function showSignIn() {
	if ($('#resource').val() && $('#date').val()) {
		$('#attendance').show();
		$('#note').hide();
	} else {
		$('#attendance').hide();
		$('#note').show();
	}
}

function showResource() {
	var resource = $('#resource').val();
	var date = $('#date').val();
	if (resource && date) {
		console.log('Resource: '+resource+'; date: '+date);
		const list = [];
		for (const pid in resources) {
			if ((typeof resources[pid][resource] !== 'undefined') && resources[pid][resource][date]) {
				list.push(resources[pid][resource][date]);
			}
		}
		$('#prior_attendance_title').html('Already Signed In');
		$('#prior_attendance').html(list.join('\n'));
	} else {
		$('#prior_attendance').html('');
		$('#prior_attendance_title').html('&nbsp;');
	}
}

function getNameDelimiter() {
	return '\n\v ';
}

function getNamePrefix() {
	return 'Multiple Matches:\n\v ';
}

function reformatAndSplitLines(lines) {
	const splitLines = lines.split(/\n/);
	const newLines = [];
	for (let i=0; i < splitLines.length; i++) {
		const line = splitLines[i];
		if (line[0] === '\v') {
			const prevIdx = (newLines.length > 0) ? newLines.length - 1 : 0;
			newLines[prevIdx] += '\n' + line;
		} else {
			newLines.push(line);
		}
	}
	return newLines;
}

function recalculateNames(lines) {
	const reformattedLines = reformatAndSplitLines(lines);
	let txtOut = '';
	const prefixRegEx = new RegExp(getNamePrefix());
	for (let i = 0; i < reformattedLines.length; i++) {
		if (reformattedLines[i]) {
			const line = reformattedLines[i].toLowerCase();
			let matchedName = '';

			for (const pid in names) {
				for (const rec in names[pid]) {
					const name = {};
					name['f'] = names[pid][rec]['first'].split(/[\s\-\(\)]/);
					name['l'] = names[pid][rec]['last'].split(/[\s\-\(\)]/);

					// filter out empty places
					for (const place in name) {
						const ary = [];
						for (let k = 0; k < name[place].length; k++) {
							if (name[place][k]) {
								ary.push(name[place][k]);
							}
						}
						name[place] = ary;
					}

					const matches = [];
					for (const place in name) {
						for (let k = 0; k < name[place].length; k++) {
							const re = new RegExp(name[place][k].toLowerCase());
							if (line.match(re)) {
								matches.push(place);
								break;
							}
						}
					}

					// at least 2 name matches on first or last
					if (matches.length >= 2) {
						const name = names[pid][rec]['first'] + ' ' + names[pid][rec]['last'] + ' (' + projects[pid] + ')';
						if (matchedName && matchedName.match(prefixRegEx)) {
							matchedName += getNameDelimiter()+name;
						} else if (matchedName) {
							matchedName = getNamePrefix()+matchedName+getNameDelimiter()+name;
						} else {
							matchedName = name;
						}
					}
				}
			}
			txtOut += matchedName + '\n';
		} else {
			txtOut += '\n';
		} 
	}
	return txtOut;
}
