<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2005-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 */

/**
 * BaseRecurrence
 * this class is superclass for recurrence for appointments and tasks. This class provides all
 * basic features of recurrence.
 */
abstract class BaseRecurrence {
	/**
	 * @var resource Mapi Message Store (may be null if readonly)
	 */
	public $store;

	/**
	 * @var mixed Mapi Message (may be null if readonly)
	 */
	public $message;

	/**
	 * @var array Message Properties
	 */
	public $messageprops;

	/**
	 * @var array list of property tags
	 */
	public $proptags;

	/**
	 * @var mixed recurrence data of this calendar item
	 */
	public $recur;

	/**
	 * @var mixed Timezone data of this calendar item
	 */
	public $tz;

	/**
	 * Constructor.
	 *
	 * @param resource $store   MAPI Message Store Object
	 * @param mixed    $message the MAPI (appointment) message
	 */
	public function __construct($store, $message) {
		$this->store = $store;

		if (is_array($message)) {
			$this->messageprops = $message;
		}
		else {
			$this->message = $message;
			$this->messageprops = mapi_getprops($this->message, $this->proptags);
		}

		if (isset($this->messageprops[$this->proptags["recurring_data"]])) {
			// There is a possibility that recurr blob can be more than 255 bytes so get full blob through stream interface
			if (strlen($this->messageprops[$this->proptags["recurring_data"]]) >= 255) {
				$this->getFullRecurrenceBlob();
			}

			$this->recur = $this->parseRecurrence($this->messageprops[$this->proptags["recurring_data"]]);
		}
		if (isset($this->proptags["timezone_data"], $this->messageprops[$this->proptags["timezone_data"]])) {
			$this->tz = $this->parseTimezone($this->messageprops[$this->proptags["timezone_data"]]);
		}
	}

	public function getRecurrence() {
		return $this->recur;
	}

	public function getFullRecurrenceBlob(): void {
		$message = mapi_msgstore_openentry($this->store, $this->messageprops[PR_ENTRYID]);

		$recurrBlob = '';
		$stream = mapi_openproperty($message, $this->proptags["recurring_data"], IID_IStream, 0, 0);
		$stat = mapi_stream_stat($stream);

		for ($i = 0; $i < $stat['cb']; $i += 1024) {
			$recurrBlob .= mapi_stream_read($stream, 1024);
		}

		if (!empty($recurrBlob)) {
			$this->messageprops[$this->proptags["recurring_data"]] = $recurrBlob;
		}
	}

	/**
	 * Function for parsing the Recurrence value of a Calendar item.
	 *
	 * Retrieve it from Named Property 0x8216 as a PT_BINARY and pass the
	 * data to this function
	 *
	 * Returns a structure containing the data:
	 *
	 * type  - type of recurrence: day=10, week=11, month=12, year=13
	 * subtype - type of day recurrence: 2=monthday (ie 21st day of month), 3=nday'th weekdays (ie. 2nd Tuesday and Wednesday)
	 * start - unix timestamp of first occurrence
	 * end  - unix timestamp of last occurrence (up to and including), so when start == end -> occurrences = 1
	 * numoccur     - occurrences (may be very large when there is no end data)
	 *
	 * then, for each type:
	 *
	 * Daily:
	 *  everyn - every [everyn] days in minutes
	 *  regen - regenerating event (like tasks)
	 *
	 * Weekly:
	 *  everyn - every [everyn] weeks in weeks
	 *  regen - regenerating event (like tasks)
	 *  weekdays - bitmask of week days, where each bit is one weekday (weekdays & 1 = Sunday, weekdays & 2 = Monday, etc)
	 *
	 * Monthly:
	 *  everyn - every [everyn] months
	 *  regen - regenerating event (like tasks)
	 *
	 *  subtype 2:
	 *   monthday - on day [monthday] of the month
	 *
	 *  subtype 3:
	 *   weekdays - bitmask of week days, where each bit is one weekday (weekdays & 1 = Sunday, weekdays & 2 = Monday, etc)
	 *   nday - on [nday]'th [weekdays] of the month
	 *
	 * Yearly:
	 *  everyn - every [everyn] months (12, 24, 36, ...)
	 *  month - in month [month] (although the month is encoded in minutes since the startning of the year ........)
	 *  regen - regenerating event (like tasks)
	 *
	 *  subtype 2:
	 *   monthday - on day [monthday] of the month
	 *
	 *  subtype 3:
	 *   weekdays - bitmask of week days, where each bit is one weekday (weekdays & 1 = Sunday, weekdays & 2 = Monday, etc)
	 *   nday - on [nday]'th [weekdays] of the month [month]
	 *
	 * @param string $rdata Binary string
	 *
	 * @return (((false|int|mixed|string)[]|int)[]|int|mixed)[]|null recurrence data
	 *
	 * @psalm-return array{changed_occurrences: array<int, array{basedate: false|int, start: int, end: int, bitmask: mixed, subject?: false|string, remind_before?: mixed, reminder_set?: mixed, location?: false|string, busystatus?: mixed, alldayevent?: mixed, label?: mixed, ex_start_datetime?: mixed, ex_end_datetime?: mixed, ex_orig_date?: mixed}>, deleted_occurrences: list<int>, type?: int|mixed, subtype?: mixed, month?: mixed, everyn?: mixed, regen?: mixed, monthday?: mixed, weekdays?: 0|mixed, nday?: mixed, term?: int|mixed, numoccur?: mixed, numexcept?: mixed, numexceptmod?: mixed, start?: int, end?: int, startocc?: mixed, endocc?: mixed}|null
	 */
	public function parseRecurrence($rdata) {
		if (strlen($rdata) < 10) {
			return;
		}

		$ret = [];
		$ret["changed_occurrences"] = [];
		$ret["deleted_occurrences"] = [];

		$data = unpack("vReaderVersion/vWriterVersion/vrtype/vrtype2/vCalendarType", $rdata);

		// Do some recurrence validity checks
		if ($data['ReaderVersion'] != 0x3004 || $data['WriterVersion'] != 0x3004) {
			return $ret;
		}

		if (!in_array($data["rtype"], [IDC_RCEV_PAT_ORB_DAILY, IDC_RCEV_PAT_ORB_WEEKLY, IDC_RCEV_PAT_ORB_MONTHLY, IDC_RCEV_PAT_ORB_YEARLY])) {
			return $ret;
		}

		if (!in_array($data["rtype2"], [rptDay, rptWeek, rptMonth, rptMonthNth, rptMonthEnd, rptHjMonth, rptHjMonthNth, rptHjMonthEnd])) {
			return $ret;
		}

		if (!in_array($data['CalendarType'], [MAPI_CAL_DEFAULT, MAPI_CAL_GREGORIAN])) {
			return $ret;
		}

		$ret["type"] = (int) $data["rtype"] > 0x2000 ? (int) $data["rtype"] - 0x2000 : $data["rtype"];
		$ret["subtype"] = $data["rtype2"];
		$rdata = substr($rdata, 10);

		switch ($data["rtype"]) {
			case IDC_RCEV_PAT_ORB_DAILY:
				if (strlen($rdata) < 12) {
					return $ret;
				}

				$data = unpack("Vunknown/Veveryn/Vregen", $rdata);
				if ($data["everyn"] > 1438560) { // minutes for 999 days
					return $ret;
				}
				$ret["everyn"] = $data["everyn"];
				$ret["regen"] = $data["regen"];

				switch ($ret["subtype"]) {
					case rptDay:
						$rdata = substr($rdata, 12);
						break;

					case rptWeek:
						$rdata = substr($rdata, 16);
						break;
				}

				break;

			case IDC_RCEV_PAT_ORB_WEEKLY:
				if (strlen($rdata) < 16) {
					return $ret;
				}

				$data = unpack("Vconst1/Veveryn/Vregen", $rdata);
				if ($data["everyn"] > 99) {
					return $ret;
				}

				$rdata = substr($rdata, 12);

				$ret["everyn"] = $data["everyn"];
				$ret["regen"] = $data["regen"];
				$ret["weekdays"] = 0;

				if ($data["regen"] == 0) {
					$data = unpack("Vweekdays", $rdata);
					$rdata = substr($rdata, 4);

					$ret["weekdays"] = $data["weekdays"];
				}
				break;

			case IDC_RCEV_PAT_ORB_MONTHLY:
				if (strlen($rdata) < 16) {
					return $ret;
				}

				$data = unpack("Vconst1/Veveryn/Vregen/Vmonthday", $rdata);
				if ($data["everyn"] > 99) {
					return $ret;
				}

				$ret["everyn"] = $data["everyn"];
				$ret["regen"] = $data["regen"];

				if ($ret["subtype"] == rptMonthNth) {
					$ret["weekdays"] = $data["monthday"];
				}
				else {
					$ret["monthday"] = $data["monthday"];
				}

				$rdata = substr($rdata, 16);
				if ($ret["subtype"] == rptMonthNth) {
					$data = unpack("Vnday", $rdata);
					$ret["nday"] = $data["nday"];
					$rdata = substr($rdata, 4);
				}
				break;

			case IDC_RCEV_PAT_ORB_YEARLY:
				if (strlen($rdata) < 16) {
					return $ret;
				}

				$data = unpack("Vmonth/Veveryn/Vregen/Vmonthday", $rdata);
				// recurring yearly tasks have a period in months multiple by 12
				if ($data['regen'] && $data["everyn"] % 12 != 0) {
					return $ret;
				}
				if (!$data['regen'] && $data["everyn"] != 12) {
					return $ret;
				}

				$ret["month"] = $data["month"];
				$ret["everyn"] = $data["everyn"];
				$ret["regen"] = $data["regen"];

				if ($ret["subtype"] == rptMonthNth) {
					$ret["weekdays"] = $data["monthday"];
				}
				else {
					$ret["monthday"] = $data["monthday"];
				}

				$rdata = substr($rdata, 16);

				if ($ret["subtype"] == rptMonthNth) {
					$data = unpack("Vnday", $rdata);
					$ret["nday"] = $data["nday"];
					$rdata = substr($rdata, 4);
				}
				break;
		}

		if (strlen($rdata) < 16) {
			return $ret;
		}

		$data = unpack("Vterm/Vnumoccur/Vconst2/Vnumexcept", $rdata);
		$rdata = substr($rdata, 16);
		if (!in_array($data["term"], [IDC_RCEV_PAT_ERB_END, IDC_RCEV_PAT_ERB_AFTERNOCCUR, IDC_RCEV_PAT_ERB_NOEND, 0xFFFFFFFF])) {
			return $ret;
		}

		$ret["term"] = (int) $data["term"] > 0x2000 ? (int) $data["term"] - 0x2000 : $data["term"];
		$ret["numoccur"] = $data["numoccur"];
		$ret["numexcept"] = $data["numexcept"];

		// exc_base_dates are *all* the base dates that have been either deleted or modified
		$exc_base_dates = [];
		for ($i = 0; $i < $ret["numexcept"]; ++$i) {
			if (strlen($rdata) < 4) {
				// We shouldn't arrive here, because that implies
				// numexcept does not match the amount of data
				// which is available for the exceptions.
				return $ret;
			}
			$data = unpack("Vbasedate", $rdata);
			$rdata = substr($rdata, 4);
			$exc_base_dates[] = $this->recurDataToUnixData($data["basedate"]);
		}

		if (strlen($rdata) < 4) {
			return $ret;
		}

		$data = unpack("Vnumexceptmod", $rdata);
		$rdata = substr($rdata, 4);

		$ret["numexceptmod"] = $data["numexceptmod"];

		// exc_changed are the base dates of *modified* occurrences. exactly what is modified
		// is in the attachments *and* in the data further down this function.
		$exc_changed = [];
		for ($i = 0; $i < $ret["numexceptmod"]; ++$i) {
			if (strlen($rdata) < 4) {
				// We shouldn't arrive here, because that implies
				// numexceptmod does not match the amount of data
				// which is available for the exceptions.
				return $ret;
			}
			$data = unpack("Vstartdate", $rdata);
			$rdata = substr($rdata, 4);
			$exc_changed[] = $this->recurDataToUnixData($data["startdate"]);
		}

		if (strlen($rdata) < 8) {
			return $ret;
		}

		$data = unpack("Vstart/Vend", $rdata);
		$rdata = substr($rdata, 8);

		$ret["start"] = $this->recurDataToUnixData($data["start"]);
		$ret["end"] = $this->recurDataToUnixData($data["end"]);

		// this is where task recurrence stop
		if (strlen($rdata) < 16) {
			return $ret;
		}

		$data = unpack("Vreaderversion/Vwriterversion/Vstartmin/Vendmin", $rdata);
		$rdata = substr($rdata, 16);

		$ret["startocc"] = $data["startmin"];
		$ret["endocc"] = $data["endmin"];
		$writerversion = $data["writerversion"];

		$data = unpack("vnumber", $rdata);
		$rdata = substr($rdata, 2);

		$nexceptions = $data["number"];
		$exc_changed_details = [];

		// Parse n modified exceptions
		for ($i = 0; $i < $nexceptions; ++$i) {
			$item = [];

			// Get exception startdate, enddate and basedate (the date at which the occurrence would have started)
			$data = unpack("Vstartdate/Venddate/Vbasedate", $rdata);
			$rdata = substr($rdata, 12);

			// Convert recurtimestamp to unix timestamp
			$startdate = $this->recurDataToUnixData($data["startdate"]);
			$enddate = $this->recurDataToUnixData($data["enddate"]);
			$basedate = $this->recurDataToUnixData($data["basedate"]);

			// Set the right properties
			$item["basedate"] = $this->dayStartOf($basedate);
			$item["start"] = $startdate;
			$item["end"] = $enddate;

			$data = unpack("vbitmask", $rdata);
			$rdata = substr($rdata, 2);
			$item["bitmask"] = $data["bitmask"]; // save bitmask for extended exceptions

			// Bitmask to verify what properties are changed
			$bitmask = $data["bitmask"];

			// ARO_SUBJECT: 0x0001
			// Look for field: SubjectLength (2b), SubjectLength2 (2b) and Subject
			if ($bitmask & (1 << 0)) {
				$data = unpack("vnull_length/vlength", $rdata);
				$rdata = substr($rdata, 4);

				$length = $data["length"];
				$item["subject"] = ""; // Normalized subject
				for ($j = 0; $j < $length && strlen($rdata); ++$j) {
					$data = unpack("Cchar", $rdata);
					$rdata = substr($rdata, 1);

					$item["subject"] .= chr($data["char"]);
				}
			}

			// ARO_MEETINGTYPE: 0x0002
			if ($bitmask & (1 << 1)) {
				$rdata = substr($rdata, 4);
				// Attendees modified: no data here (only in attachment)
			}

			// ARO_REMINDERDELTA: 0x0004
			// Look for field: ReminderDelta (4b)
			if ($bitmask & (1 << 2)) {
				$data = unpack("Vremind_before", $rdata);
				$rdata = substr($rdata, 4);

				$item["remind_before"] = $data["remind_before"];
			}

			// ARO_REMINDER: 0x0008
			// Look field: ReminderSet (4b)
			if ($bitmask & (1 << 3)) {
				$data = unpack("Vreminder_set", $rdata);
				$rdata = substr($rdata, 4);

				$item["reminder_set"] = $data["reminder_set"];
			}

			// ARO_LOCATION: 0x0010
			// Look for fields: LocationLength (2b), LocationLength2 (2b) and Location
			// Similar to ARO_SUBJECT above.
			if ($bitmask & (1 << 4)) {
				$data = unpack("vnull_length/vlength", $rdata);
				$rdata = substr($rdata, 4);

				$item["location"] = "";

				$length = $data["length"];
				$data = substr($rdata, 0, $length);
				$rdata = substr($rdata, $length);

				$item["location"] .= $data;
			}

			// ARO_BUSYSTATUS: 0x0020
			// Look for field: BusyStatus (4b)
			if ($bitmask & (1 << 5)) {
				$data = unpack("Vbusystatus", $rdata);
				$rdata = substr($rdata, 4);

				$item["busystatus"] = $data["busystatus"];
			}

			// ARO_ATTACHMENT: 0x0040
			if ($bitmask & (1 << 6)) {
				// no data: RESERVED
				$rdata = substr($rdata, 4);
			}

			// ARO_SUBTYPE: 0x0080
			// Look for field: SubType (4b). Determines whether it is an allday event.
			if ($bitmask & (1 << 7)) {
				$data = unpack("Vallday", $rdata);
				$rdata = substr($rdata, 4);

				$item["alldayevent"] = $data["allday"];
			}

			// ARO_APPTCOLOR: 0x0100
			// Look for field: AppointmentColor (4b)
			if ($bitmask & (1 << 8)) {
				$data = unpack("Vlabel", $rdata);
				$rdata = substr($rdata, 4);

				$item["label"] = $data["label"];
			}

			// ARO_EXCEPTIONAL_BODY: 0x0200
			if ($bitmask & (1 << 9)) {
				// Notes or Attachments modified: no data here (only in attachment)
			}

			array_push($exc_changed_details, $item);
		}

		/**
		 * We now have $exc_changed, $exc_base_dates and $exc_changed_details
		 * We will ignore $exc_changed, as this information is available in $exc_changed_details
		 * also. If an item is in $exc_base_dates and NOT in $exc_changed_details, then the item
		 * has been deleted.
		 */

		// Find deleted occurrences
		$deleted_occurrences = [];

		foreach ($exc_base_dates as $base_date) {
			$found = false;

			foreach ($exc_changed_details as $details) {
				if ($details["basedate"] == $base_date) {
					$found = true;
					break;
				}
			}
			if (!$found) {
				// item was not in exc_changed_details, so it must be deleted
				$deleted_occurrences[] = $base_date;
			}
		}

		$ret["deleted_occurrences"] = $deleted_occurrences;
		$ret["changed_occurrences"] = $exc_changed_details;

		// enough data for normal exception (no extended data)
		if (strlen($rdata) < 8) {
			return $ret;
		}

		$data = unpack("Vreservedsize", $rdata);
		$rdata = substr($rdata, 4 + $data["reservedsize"]);

		for ($i = 0; $i < $nexceptions; ++$i) {
			// subject and location in ucs-2 to utf-8
			if ($writerversion >= 0x3009) {
				$data = unpack("Vsize/Vvalue", $rdata); // size includes sizeof(value)==4
				$rdata = substr($rdata, 4 + $data["size"]);
			}

			$data = unpack("Vreservedsize", $rdata);
			$rdata = substr($rdata, 4 + $data["reservedsize"]);

			// ARO_SUBJECT(0x01) | ARO_LOCATION(0x10)
			if ($exc_changed_details[$i]["bitmask"] & 0x11) {
				$data = unpack("Vstart/Vend/Vorig", $rdata);
				$rdata = substr($rdata, 4 * 3);

				$exc_changed_details[$i]["ex_start_datetime"] = $data["start"];
				$exc_changed_details[$i]["ex_end_datetime"] = $data["end"];
				$exc_changed_details[$i]["ex_orig_date"] = $data["orig"];
			}

			// ARO_SUBJECT
			if ($exc_changed_details[$i]["bitmask"] & 0x01) {
				// decode ucs2 string to utf-8
				$data = unpack("vlength", $rdata);
				$rdata = substr($rdata, 2);
				$length = $data["length"];
				$data = substr($rdata, 0, $length * 2);
				$rdata = substr($rdata, $length * 2);
				$subject = iconv("UCS-2LE", "UTF-8", $data);
				// replace subject with unicode subject
				$exc_changed_details[$i]["subject"] = $subject;
			}

			// ARO_LOCATION
			if ($exc_changed_details[$i]["bitmask"] & 0x10) {
				// decode ucs2 string to utf-8
				$data = unpack("vlength", $rdata);
				$rdata = substr($rdata, 2);
				$length = $data["length"];
				$data = substr($rdata, 0, $length * 2);
				$rdata = substr($rdata, $length * 2);
				$location = iconv("UCS-2LE", "UTF-8", $data);
				// replace subject with unicode subject
				$exc_changed_details[$i]["location"] = $location;
			}

			// ARO_SUBJECT(0x01) | ARO_LOCATION(0x10)
			if ($exc_changed_details[$i]["bitmask"] & 0x11) {
				$data = unpack("Vreservedsize", $rdata);
				$rdata = substr($rdata, 4 + $data["reservedsize"]);
			}
		}

		// update with extended data
		$ret["changed_occurrences"] = $exc_changed_details;

		return $ret;
	}

	/**
	 * Saves the recurrence data to the recurrence property.
	 */
	public function saveRecurrence(): void {
		// Only save if a message was passed
		if (!isset($this->message)) {
			return;
		}

		// Abort if no recurrence was set
		if (!isset(
			$this->recur["type"],
			$this->recur["subtype"],
			$this->recur["start"],
			$this->recur["end"],
			$this->recur["startocc"],
			$this->recur["endocc"])
		) {
			return;
		}

		$rtype = 0x2000 + (int) $this->recur["type"];

		// Don't allow invalid type and subtype values
		if (!in_array($rtype, [IDC_RCEV_PAT_ORB_DAILY, IDC_RCEV_PAT_ORB_WEEKLY, IDC_RCEV_PAT_ORB_MONTHLY, IDC_RCEV_PAT_ORB_YEARLY])) {
			return;
		}

		if (!in_array((int) $this->recur["subtype"], [rptDay, rptWeek, rptMonth, rptMonthNth, rptMonthEnd, rptHjMonth, rptHjMonthNth, rptHjMonthEnd])) {
			return;
		}

		$rdata = pack("vvvvv", 0x3004, 0x3004, $rtype, (int) $this->recur["subtype"], CAL_DEFAULT);
		$weekstart = 1; // monday
		$forwardcount = 0;
		$restocc = 0;
		$dayofweek = (int) gmdate("w", (int) $this->recur["start"]); // 0 (for Sunday) through 6 (for Saturday)

		// Terminate
		$term = (int) $this->recur["term"] < 0x2000 ? 0x2000 + (int) $this->recur["term"] : (int) $this->recur["term"];

		switch ($rtype) {
			case IDC_RCEV_PAT_ORB_DAILY:
				if (!isset($this->recur["everyn"]) || (int) $this->recur["everyn"] > 1438560 || (int) $this->recur["everyn"] < 0) { // minutes for 999 days
					return;
				}

				if ($this->recur["subtype"] == rptWeek) {
					// Daily every workday
					$rdata .= pack("VVVV", 6 * 24 * 60, 1, 0, 0x3E);
				}
				else {
					// Calc first occ
					$firstocc = $this->unixDataToRecurData($this->recur["start"]) % ((int) $this->recur["everyn"]);

					$rdata .= pack("VVV", $firstocc, (int) $this->recur["everyn"], $this->recur["regen"] ? 1 : 0);
				}
				break;

			case IDC_RCEV_PAT_ORB_WEEKLY:
				if (!isset($this->recur["everyn"]) || $this->recur["everyn"] > 99 || (int) $this->recur["everyn"] < 0) {
					return;
				}

				if (!$this->recur["regen"] && !isset($this->recur["weekdays"])) {
					return;
				}

				// No need to calculate startdate if sliding flag was set.
				if (!$this->recur['regen']) {
					// Calculate start date of recurrence

					// Find the first day that matches one of the weekdays selected
					$daycount = 0;
					$dayskip = -1;
					for ($j = 0; $j < 7; ++$j) {
						if (((int) $this->recur["weekdays"]) & (1 << (($dayofweek + $j) % 7))) {
							if ($dayskip == -1) {
								$dayskip = $j;
							}

							++$daycount;
						}
					}

					// $dayskip is the number of days to skip from the startdate until the first occurrence
					// $daycount is the number of days per week that an occurrence occurs

					$weekskip = 0;
					if (($dayofweek < $weekstart && $dayskip > 0) || ($dayofweek + $dayskip) > 6) {
						$weekskip = 1;
					}

					// Check if the recurrence ends after a number of occurrences, in that case we must calculate the
					// remaining occurrences based on the start of the recurrence.
					if ($term == IDC_RCEV_PAT_ERB_AFTERNOCCUR) {
						// $weekskip is the amount of weeks to skip from the startdate before the first occurrence
						// $forwardcount is the maximum number of week occurrences we can go ahead after the first occurrence that
						// is still inside the recurrence. We subtract one to make sure that the last week is never forwarded over
						// (eg when numoccur = 2, and daycount = 1)
						$forwardcount = floor((int) ($this->recur["numoccur"] - 1) / $daycount);

						// $restocc is the number of occurrences left after $forwardcount whole weeks of occurrences, minus one
						// for the occurrence on the first day
						$restocc = ((int) $this->recur["numoccur"]) - ($forwardcount * $daycount) - 1;

						// $forwardcount is now the number of weeks we can go forward and still be inside the recurrence
						$forwardcount *= (int) $this->recur["everyn"];
					}

					// The real start is start + dayskip + weekskip-1 (since dayskip will already bring us into the next week)
					$this->recur["start"] = ((int) $this->recur["start"]) + ($dayskip * 24 * 60 * 60) + ($weekskip * (((int) $this->recur["everyn"]) - 1) * 7 * 24 * 60 * 60);
				}

				// Calc first occ
				$firstocc = $this->unixDataToRecurData($this->recur["start"]) % (((int) $this->recur["everyn"]) * 7 * 24 * 60);

				$firstocc -= (((int) gmdate("w", (int) $this->recur["start"])) - 1) * 24 * 60;

				if ($this->recur["regen"]) {
					$rdata .= pack("VVV", $firstocc, (int) $this->recur["everyn"], 1);
				}
				else {
					$rdata .= pack("VVVV", $firstocc, (int) $this->recur["everyn"], 0, (int) $this->recur["weekdays"]);
				}
				break;

			case IDC_RCEV_PAT_ORB_MONTHLY:
			case IDC_RCEV_PAT_ORB_YEARLY:
				if (!isset($this->recur["everyn"])) {
					return;
				}
				if ($rtype == IDC_RCEV_PAT_ORB_YEARLY && !isset($this->recur["month"])) {
					return;
				}

				if ($rtype == IDC_RCEV_PAT_ORB_MONTHLY) {
					$everyn = (int) $this->recur["everyn"];
					if ($everyn > 99 || $everyn < 0) {
						return;
					}
				}
				else {
					$everyn = $this->recur["regen"] ? ((int) $this->recur["everyn"]) * 12 : 12;
				}

				// Get montday/month/year of original start
				$curmonthday = gmdate("j", (int) $this->recur["start"]);
				$curyear = gmdate("Y", (int) $this->recur["start"]);
				$curmonth = gmdate("n", (int) $this->recur["start"]);

				// Check if the recurrence ends after a number of occurrences, in that case we must calculate the
				// remaining occurrences based on the start of the recurrence.
				if ($term == IDC_RCEV_PAT_ERB_AFTERNOCCUR) {
					// $forwardcount is the number of occurrences we can skip and still be inside the recurrence range (minus
					// one to make sure there are always at least one occurrence left)
					$forwardcount = ((((int) $this->recur["numoccur"]) - 1) * $everyn);
				}

				// Get month for yearly on D'th day of month M
				if ($rtype == IDC_RCEV_PAT_ORB_YEARLY) {
					$selmonth = floor(((int) $this->recur["month"]) / (24 * 60 * 29)) + 1; // 1=jan, 2=feb, eg
				}

				switch ((int) $this->recur["subtype"]) {
					// on D day of every M month
					case rptMonth:
						if (!isset($this->recur["monthday"])) {
							return;
						}
						// Recalc startdate

						// Set on the right begin day

						// Go the beginning of the month
						$this->recur["start"] -= ($curmonthday - 1) * 24 * 60 * 60;
						// Go the the correct month day
						$this->recur["start"] += (((int) $this->recur["monthday"]) - 1) * 24 * 60 * 60;

						// If the previous calculation gave us a start date different than the original start date, then we need to skip to the first occurrence
						if (($rtype == IDC_RCEV_PAT_ORB_MONTHLY && ((int) $this->recur["monthday"]) < $curmonthday) ||
							($rtype == IDC_RCEV_PAT_ORB_YEARLY && ($selmonth != $curmonth || ($selmonth == $curmonth && ((int) $this->recur["monthday"]) < $curmonthday)))) {
							if ($rtype == IDC_RCEV_PAT_ORB_YEARLY) {
								if ($curmonth > $selmonth) {// go to next occurrence in 'everyn' months minus difference in first occurrence and original date
									$count = $everyn - ($curmonth - $selmonth);
								}
								elseif ($curmonth < $selmonth) {// go to next occurrence upto difference in first occurrence and original date
									$count = $selmonth - $curmonth;
								}
								else {
									// Go to next occurrence while recurrence start date is greater than occurrence date but within same month
									if (((int) $this->recur["monthday"]) < $curmonthday) {
										$count = $everyn;
									}
								}
							}
							else {
								$count = $everyn; // Monthly, go to next occurrence in 'everyn' months
							}

							// Forward by $count months. This is done by getting the number of days in that month and forwarding that many days
							for ($i = 0; $i < $count; ++$i) {
								$this->recur["start"] += $this->getMonthInSeconds($curyear, $curmonth);

								if ($curmonth == 12) {
									++$curyear;
									$curmonth = 0;
								}
								++$curmonth;
							}
						}

						// "start" is now pointing to the first occurrence, except that it will overshoot if the
						// month in which it occurs has less days than specified as the day of the month. So 31st
						// of each month will overshoot in february (29 days). We compensate for that by checking
						// if the day of the month we got is wrong, and then back up to the last day of the previous
						// month.
						if (((int) $this->recur["monthday"]) >= 28 && ((int) $this->recur["monthday"]) <= 31 &&
							gmdate("j", (int) $this->recur["start"]) < ((int) $this->recur["monthday"])) {
							$this->recur["start"] -= gmdate("j", (int) $this->recur["start"]) * 24 * 60 * 60;
						}

						// "start" is now the first occurrence
						if ($rtype == IDC_RCEV_PAT_ORB_MONTHLY) {
							// Calc first occ
							$monthIndex = ((((12 % $everyn) * ((((int) gmdate("Y", $this->recur["start"])) - 1601) % $everyn)) % $everyn) + (((int) gmdate("n", $this->recur["start"])) - 1)) % $everyn;

							$firstocc = 0;
							for ($i = 0; $i < $monthIndex; ++$i) {
								$firstocc += $this->getMonthInSeconds(1601 + floor($i / 12), ($i % 12) + 1) / 60;
							}

							$rdata .= pack("VVVV", $firstocc, $everyn, $this->recur["regen"], (int) $this->recur["monthday"]);
						}
						else {
							// Calc first occ
							$firstocc = 0;
							$monthIndex = (int) gmdate("n", $this->recur["start"]);
							for ($i = 1; $i < $monthIndex; ++$i) {
								$firstocc += $this->getMonthInSeconds(1601 + floor($i / 12), $i) / 60;
							}

							$rdata .= pack("VVVV", $firstocc, $everyn, $this->recur["regen"], (int) $this->recur["monthday"]);
						}
						break;

					case rptMonthNth:
						// monthly: on Nth weekday of every M month
						// yearly: on Nth weekday of M month
						if (!isset($this->recur["weekdays"], $this->recur["nday"])) {
							return;
						}

						$weekdays = (int) $this->recur["weekdays"];
						$nday = (int) $this->recur["nday"];

						// Calc startdate
						$monthbegindow = (int) $this->recur["start"];

						if ($nday == 5) {
							// Set date on the last day of the last month
							$monthbegindow += (gmdate("t", $monthbegindow) - gmdate("j", $monthbegindow)) * 24 * 60 * 60;
						}
						else {
							// Set on the first day of the month
							$monthbegindow -= ((gmdate("j", $monthbegindow) - 1) * 24 * 60 * 60);
						}

						if ($rtype == IDC_RCEV_PAT_ORB_YEARLY) {
							// Set on right month
							if ($selmonth < $curmonth) {
								$tmp = 12 - $curmonth + $selmonth;
							}
							else {
								$tmp = ($selmonth - $curmonth);
							}

							for ($i = 0; $i < $tmp; ++$i) {
								$monthbegindow += $this->getMonthInSeconds($curyear, $curmonth);

								if ($curmonth == 12) {
									++$curyear;
									$curmonth = 0;
								}
								++$curmonth;
							}
						}
						else {
							// Check or you exist in the right month

							$dayofweek = gmdate("w", $monthbegindow);
							for ($i = 0; $i < 7; ++$i) {
								if ($nday == 5 && (($dayofweek - $i) % 7 >= 0) && (1 << (($dayofweek - $i) % 7)) & $weekdays) {
									$day = gmdate("j", $monthbegindow) - $i;
									break;
								}
								if ($nday != 5 && (1 << (($dayofweek + $i) % 7)) & $weekdays) {
									$day = (($nday - 1) * 7) + ($i + 1);
									break;
								}
							}

							// Goto the next X month
							if (isset($day) && ($day < gmdate("j", (int) $this->recur["start"]))) {
								if ($nday == 5) {
									$monthbegindow += 24 * 60 * 60;
									if ($curmonth == 12) {
										++$curyear;
										$curmonth = 0;
									}
									++$curmonth;
								}

								for ($i = 0; $i < $everyn; ++$i) {
									$monthbegindow += $this->getMonthInSeconds($curyear, $curmonth);

									if ($curmonth == 12) {
										++$curyear;
										$curmonth = 0;
									}
									++$curmonth;
								}

								if ($nday == 5) {
									$monthbegindow -= 24 * 60 * 60;
								}
							}
						}

						// FIXME: weekstart?

						$day = 0;
						// Set start on the right day
						$dayofweek = gmdate("w", $monthbegindow);
						for ($i = 0; $i < 7; ++$i) {
							if ($nday == 5 && (($dayofweek - $i) % 7) >= 0 && (1 << (($dayofweek - $i) % 7)) & $weekdays) {
								$day = $i;
								break;
							}
							if ($nday != 5 && (1 << (($dayofweek + $i) % 7)) & $weekdays) {
								$day = ($nday - 1) * 7 + ($i + 1);
								break;
							}
						}
						if ($nday == 5) {
							$monthbegindow -= $day * 24 * 60 * 60;
						}
						else {
							$monthbegindow += ($day - 1) * 24 * 60 * 60;
						}

						$firstocc = 0;
						if ($rtype == IDC_RCEV_PAT_ORB_MONTHLY) {
							// Calc first occ
							$monthIndex = ((((12 % $everyn) * (((int) gmdate("Y", $this->recur["start"]) - 1601) % $everyn)) % $everyn) + (((int) gmdate("n", $this->recur["start"])) - 1)) % $everyn;

							for ($i = 0; $i < $monthIndex; ++$i) {
								$firstocc += $this->getMonthInSeconds(1601 + floor($i / 12), ($i % 12) + 1) / 60;
							}

							$rdata .= pack("VVVVV", $firstocc, $everyn, 0, $weekdays, $nday);
						}
						else {
							// Calc first occ
							$monthIndex = (int) gmdate("n", $this->recur["start"]);

							for ($i = 1; $i < $monthIndex; ++$i) {
								$firstocc += $this->getMonthInSeconds(1601 + floor($i / 12), $i) / 60;
							}

							$rdata .= pack("VVVVV", $firstocc, $everyn, 0, $weekdays, $nday);
						}
						break;
				}
				break;
		}

		if (!isset($this->recur["term"])) {
			return;
		}

		$rdata .= pack("V", $term);

		switch ($term) {
			// After the given enddate
			case IDC_RCEV_PAT_ERB_END:
				$rdata .= pack("V", 10);
				break;
				// After a number of times
			case IDC_RCEV_PAT_ERB_AFTERNOCCUR:
				if (!isset($this->recur["numoccur"])) {
					return;
				}

				$rdata .= pack("V", (int) $this->recur["numoccur"]);
				break;
				// Never ends
			case IDC_RCEV_PAT_ERB_NOEND:
				$rdata .= pack("V", 0);
				break;
		}

		// Strange little thing for the recurrence type "every workday"
		if ($rtype == IDC_RCEV_PAT_ORB_WEEKLY && ((int) $this->recur["subtype"]) == 1) {
			$rdata .= pack("V", 1);
		}
		else { // Other recurrences
			$rdata .= pack("V", 0);
		}

		// Exception data

		// Get all exceptions
		$deleted_items = $this->recur["deleted_occurrences"];
		$changed_items = $this->recur["changed_occurrences"];

		// Merge deleted and changed items into one list
		$items = $deleted_items;

		foreach ($changed_items as $changed_item) {
			array_push($items, $changed_item["basedate"]);
		}

		sort($items);

		// Add the merged list in to the rdata
		$rdata .= pack("V", count($items));
		foreach ($items as $item) {
			$rdata .= pack("V", $this->unixDataToRecurData($item));
		}

		// Loop through the changed exceptions (not deleted)
		$rdata .= pack("V", count($changed_items));
		$items = [];

		foreach ($changed_items as $changed_item) {
			$items[] = $this->dayStartOf($changed_item["start"]);
		}

		sort($items);

		// Add the changed items list int the rdata
		foreach ($items as $item) {
			$rdata .= pack("V", $this->unixDataToRecurData($item));
		}

		// Set start date
		$rdata .= pack("V", $this->unixDataToRecurData((int) $this->recur["start"]));

		// Set enddate
		switch ($term) {
			// After the given enddate
			case IDC_RCEV_PAT_ERB_END:
				$rdata .= pack("V", $this->unixDataToRecurData((int) $this->recur["end"]));
				break;
				// After a number of times
			case IDC_RCEV_PAT_ERB_AFTERNOCCUR:
				// @todo: calculate enddate with intval($this->recur["startocc"]) + intval($this->recur["duration"]) > 24 hour
				$occenddate = (int) $this->recur["start"];

				switch ($rtype) {
					case IDC_RCEV_PAT_ORB_DAILY:
						if ($this->recur["subtype"] == rptWeek) {
							// Daily every workday
							$restocc = (int) $this->recur["numoccur"];

							// Get starting weekday
							$nowtime = $this->gmtime($occenddate);
							$j = $nowtime["tm_wday"];

							while (1) {
								if (($j % 7) > 0 && ($j % 7) < 6) {
									--$restocc;
								}

								++$j;

								if ($restocc <= 0) {
									break;
								}

								$occenddate += 24 * 60 * 60;
							}
						}
						else {
							// -1 because the first day already counts (from 1-1-1980 to 1-1-1980 is 1 occurrence)
							$occenddate += (((int) $this->recur["everyn"]) * 60 * ((int) $this->recur["numoccur"] - 1));
						}
						break;

					case IDC_RCEV_PAT_ORB_WEEKLY:
						// Needed values
						// $forwardcount - number of weeks we can skip forward
						// $restocc - number of remaining occurrences after the week skip

						// Add the weeks till the last item
						$occenddate += ($forwardcount * 7 * 24 * 60 * 60);

						$dayofweek = gmdate("w", $occenddate);

						// Loop through the last occurrences until we have had them all
						for ($j = 1; $restocc > 0; ++$j) {
							// Jump to the next week (which may be N weeks away) when going over the week boundary
							if ((($dayofweek + $j) % 7) == $weekstart) {
								$occenddate += (((int) $this->recur["everyn"]) - 1) * 7 * 24 * 60 * 60;
							}

							// If this is a matching day, once less occurrence to process
							if (((int) $this->recur["weekdays"]) & (1 << (($dayofweek + $j) % 7))) {
								--$restocc;
							}

							// Next day
							$occenddate += 24 * 60 * 60;
						}

						break;

					case IDC_RCEV_PAT_ORB_MONTHLY:
					case IDC_RCEV_PAT_ORB_YEARLY:
						$curyear = gmdate("Y", (int) $this->recur["start"]);
						$curmonth = gmdate("n", (int) $this->recur["start"]);
						// $forwardcount = months

						switch ((int) $this->recur["subtype"]) {
							case rptMonth: // on D day of every M month
								while ($forwardcount > 0) {
									$occenddate += $this->getMonthInSeconds($curyear, $curmonth);

									if ($curmonth >= 12) {
										$curmonth = 1;
										++$curyear;
									}
									else {
										++$curmonth;
									}
									--$forwardcount;
								}

								// compensation between 28 and 31
								if (((int) $this->recur["monthday"]) >= 28 && ((int) $this->recur["monthday"]) <= 31 &&
									gmdate("j", $occenddate) < ((int) $this->recur["monthday"])) {
									if (gmdate("j", $occenddate) < 28) {
										$occenddate -= gmdate("j", $occenddate) * 24 * 60 * 60;
									}
									else {
										$occenddate += (gmdate("t", $occenddate) - gmdate("j", $occenddate)) * 24 * 60 * 60;
									}
								}

								break;

							case rptMonthNth: // on Nth weekday of every M month
								$nday = (int) $this->recur["nday"]; // 1 tot 5
								$weekdays = (int) $this->recur["weekdays"];

								while ($forwardcount > 0) {
									$occenddate += $this->getMonthInSeconds($curyear, $curmonth);
									if ($curmonth >= 12) {
										$curmonth = 1;
										++$curyear;
									}
									else {
										++$curmonth;
									}

									--$forwardcount;
								}

								if ($nday == 5) {
									// Set date on the last day of the last month
									$occenddate += (gmdate("t", $occenddate) - gmdate("j", $occenddate)) * 24 * 60 * 60;
								}
								else {
									// Set date on the first day of the last month
									$occenddate -= (gmdate("j", $occenddate) - 1) * 24 * 60 * 60;
								}

								$dayofweek = gmdate("w", $occenddate);
								for ($i = 0; $i < 7; ++$i) {
									if ($nday == 5 && (($dayofweek - $i) % 7) >= 0 && (1 << (($dayofweek - $i) % 7)) & $weekdays) {
										$occenddate -= $i * 24 * 60 * 60;
										break;
									}
									if ($nday != 5 && (1 << (($dayofweek + $i) % 7)) & $weekdays) {
										$occenddate += ($i + (($nday - 1) * 7)) * 24 * 60 * 60;
										break;
									}
								}

								break; // case rptMonthNth
						}

						break;
				}

				if (defined("PHP_INT_MAX") && $occenddate > PHP_INT_MAX) {
					$occenddate = PHP_INT_MAX;
				}

				$this->recur["end"] = $occenddate;

				$rdata .= pack("V", $this->unixDataToRecurData((int) $this->recur["end"]));
				break;
				// Never ends
			case IDC_RCEV_PAT_ERB_NOEND:
			default:
				$this->recur["end"] = 0x7FFFFFFF; // max date -> 2038
				$rdata .= pack("V", 0x5AE980DF);
				break;
		}

		// UTC date
		$utcstart = $this->toGMT($this->tz, (int) $this->recur["start"]);
		$utcend = $this->toGMT($this->tz, (int) $this->recur["end"]);

		// utc date+time
		$utcfirstoccstartdatetime = (isset($this->recur["startocc"])) ? $utcstart + (((int) $this->recur["startocc"]) * 60) : $utcstart;
		$utcfirstoccenddatetime = (isset($this->recur["endocc"])) ? $utcstart + (((int) $this->recur["endocc"]) * 60) : $utcstart;

		$propsToSet = [];
		// update reminder time
		$propsToSet[$this->proptags["reminder_time"]] = $utcfirstoccstartdatetime;

		// update first occurrence date
		$propsToSet[$this->proptags["startdate"]] = $propsToSet[$this->proptags["commonstart"]] = $utcfirstoccstartdatetime;
		$propsToSet[$this->proptags["duedate"]] = $propsToSet[$this->proptags["commonend"]] = $utcfirstoccenddatetime;

		// Set Outlook properties, if it is an appointment
		if (isset($this->messageprops[$this->proptags["message_class"]]) && $this->messageprops[$this->proptags["message_class"]] == "IPM.Appointment") {
			// update real begin and real end date
			$propsToSet[$this->proptags["startdate_recurring"]] = $utcstart;
			$propsToSet[$this->proptags["enddate_recurring"]] = $utcend;

			// recurrencetype
			// Strange enough is the property recurrencetype, (type-0x9) and not the CDO recurrencetype
			$propsToSet[$this->proptags["recurrencetype"]] = ((int) $this->recur["type"]) - 0x9;

			// set named prop 'side_effects' to 369, needed for Outlook to ask for single or total recurrence when deleting
			$propsToSet[$this->proptags["side_effects"]] = 369;
		}
		else {
			$propsToSet[$this->proptags["side_effects"]] = 3441;
		}

		// FlagDueBy is datetime of the first reminder occurrence. Outlook gives on this time a reminder popup dialog
		// Any change of the recurrence (including changing and deleting exceptions) causes the flagdueby to be reset
		// to the 'next' occurrence; this makes sure that deleting the next occurrence will correctly set the reminder to
		// the occurrence after that. The 'next' occurrence is defined as being the first occurrence that starts at moment X (server time)
		// with the reminder flag set.
		$reminderprops = mapi_getprops($this->message, [$this->proptags["reminder_minutes"], $this->proptags["flagdueby"]]);
		if (isset($reminderprops[$this->proptags["reminder_minutes"]])) {
			$occ = false;
			$occurrences = $this->getItems(time(), 0x7FF00000, 3, true);

			for ($i = 0, $len = count($occurrences); $i < $len; ++$i) {
				// This will actually also give us appointments that have already started, but not yet ended. Since we want the next
				// reminder that occurs after time(), we may have to skip the first few entries. We get 3 entries since that is the maximum
				// number that would be needed (assuming reminder for item X cannot be before the previous occurrence starts). Worst case:
				// time() is currently after start but before end of item, but reminder of next item has already passed (reminder for next item
				// can be DURING the previous item, eg daily allday events). In that case, the first and second items must be skipped.

				if (($occurrences[$i][$this->proptags["startdate"]] - $reminderprops[$this->proptags["reminder_minutes"]] * 60) > time()) {
					$occ = $occurrences[$i];
					break;
				}
			}

			if ($occ) {
				if (isset($reminderprops[$this->proptags["flagdueby"]])) {
					$propsToSet[$this->proptags["flagdueby"]] = $reminderprops[$this->proptags["flagdueby"]];
				}
				else {
					$propsToSet[$this->proptags["flagdueby"]] = $occ[$this->proptags["startdate"]] - ($reminderprops[$this->proptags["reminder_minutes"]] * 60);
				}
			}
			else {
				// Last reminder passed, no reminders any more.
				$propsToSet[$this->proptags["reminder"]] = false;
				$propsToSet[$this->proptags["flagdueby"]] = 0x7FF00000;
			}
		}

		// Default data
		// Second item (0x08) indicates the Outlook version (see documentation at the bottom of this file for more information)
		$rdata .= pack("VV", 0x3006, 0x3008);
		if (isset($this->recur["startocc"], $this->recur["endocc"])) {
			// Set start and endtime in minutes
			$rdata .= pack("VV", (int) $this->recur["startocc"], (int) $this->recur["endocc"]);
		}

		// Detailed exception data

		$changed_items = $this->recur["changed_occurrences"];

		$rdata .= pack("v", count($changed_items));

		foreach ($changed_items as $changed_item) {
			// Set start and end time of exception
			$rdata .= pack("V", $this->unixDataToRecurData($changed_item["start"]));
			$rdata .= pack("V", $this->unixDataToRecurData($changed_item["end"]));
			$rdata .= pack("V", $this->unixDataToRecurData($changed_item["basedate"]));

			// Bitmask
			$bitmask = 0;

			// Check for changed strings
			if (isset($changed_item["subject"])) {
				$bitmask |= 1 << 0;
			}

			if (isset($changed_item["remind_before"])) {
				$bitmask |= 1 << 2;
			}

			if (isset($changed_item["reminder_set"])) {
				$bitmask |= 1 << 3;
			}

			if (isset($changed_item["location"])) {
				$bitmask |= 1 << 4;
			}

			if (isset($changed_item["busystatus"])) {
				$bitmask |= 1 << 5;
			}

			if (isset($changed_item["alldayevent"])) {
				$bitmask |= 1 << 7;
			}

			if (isset($changed_item["label"])) {
				$bitmask |= 1 << 8;
			}

			$rdata .= pack("v", $bitmask);

			// Set "subject"
			if (isset($changed_item["subject"])) {
				// convert utf-8 to non-unicode blob string (us-ascii?)
				$subject = iconv("UTF-8", "windows-1252//TRANSLIT", $changed_item["subject"]);
				$length = strlen($subject);
				$rdata .= pack("vv", $length + 1, $length);
				$rdata .= pack("a" . $length, $subject);
			}

			if (isset($changed_item["remind_before"])) {
				$rdata .= pack("V", $changed_item["remind_before"]);
			}

			if (isset($changed_item["reminder_set"])) {
				$rdata .= pack("V", $changed_item["reminder_set"]);
			}

			if (isset($changed_item["location"])) {
				$location = iconv("UTF-8", "windows-1252//TRANSLIT", $changed_item["location"]);
				$length = strlen($location);
				$rdata .= pack("vv", $length + 1, $length);
				$rdata .= pack("a" . $length, $location);
			}

			if (isset($changed_item["busystatus"])) {
				$rdata .= pack("V", $changed_item["busystatus"]);
			}

			if (isset($changed_item["alldayevent"])) {
				$rdata .= pack("V", $changed_item["alldayevent"]);
			}

			if (isset($changed_item["label"])) {
				$rdata .= pack("V", $changed_item["label"]);
			}
		}

		$rdata .= pack("V", 0);

		// write extended data
		foreach ($changed_items as $changed_item) {
			$rdata .= pack("V", 0);
			if (isset($changed_item["subject"]) || isset($changed_item["location"])) {
				$rdata .= pack("V", $this->unixDataToRecurData($changed_item["start"]));
				$rdata .= pack("V", $this->unixDataToRecurData($changed_item["end"]));
				$rdata .= pack("V", $this->unixDataToRecurData($changed_item["basedate"]));
			}

			if (isset($changed_item["subject"])) {
				$subject = iconv("UTF-8", "UCS-2LE", $changed_item["subject"]);
				$length = iconv_strlen($subject, "UCS-2LE");
				$rdata .= pack("v", $length);
				$rdata .= pack("a" . $length * 2, $subject);
			}

			if (isset($changed_item["location"])) {
				$location = iconv("UTF-8", "UCS-2LE", $changed_item["location"]);
				$length = iconv_strlen($location, "UCS-2LE");
				$rdata .= pack("v", $length);
				$rdata .= pack("a" . $length * 2, $location);
			}

			if (isset($changed_item["subject"]) || isset($changed_item["location"])) {
				$rdata .= pack("V", 0);
			}
		}

		$rdata .= pack("V", 0);

		// Set props
		$propsToSet[$this->proptags["recurring_data"]] = $rdata;
		$propsToSet[$this->proptags["recurring"]] = true;
		if (isset($this->tz) && $this->tz) {
			$timezone = "GMT";
			if ($this->tz["timezone"] != 0) {
				// Create user readable timezone information
				$timezone = sprintf(
					"(GMT %s%02d:%02d)",-$this->tz["timezone"] > 0 ? "+" : "-",
					abs($this->tz["timezone"] / 60),
					abs($this->tz["timezone"] % 60)
				);
			}
			$propsToSet[$this->proptags["timezone_data"]] = $this->getTimezoneData($this->tz);
			$propsToSet[$this->proptags["timezone"]] = $timezone;
		}
		mapi_setprops($this->message, $propsToSet);
	}

	/**
	 * Function which converts a recurrence date timestamp to an unix date timestamp.
	 *
	 * @author Steve Hardy
	 *
	 * @param int $rdate the date which will be converted
	 *
	 * @return int the converted date
	 */
	public function recurDataToUnixData($rdate) {
		return ($rdate - 194074560) * 60;
	}

	/**
	 * Function which converts an unix date timestamp to recurrence date timestamp.
	 *
	 * @author Johnny Biemans
	 *
	 * @param int $date the date which will be converted
	 *
	 * @return float|int the converted date in minutes
	 */
	public function unixDataToRecurData($date) {
		return ($date / 60) + 194074560;
	}

	/**
	 * gmtime() doesn't exist in standard PHP, so we have to implement it ourselves.
	 *
	 * @author Steve Hardy
	 *
	 * @param mixed $ts
	 *
	 * @return float|int
	 */
	public function GetTZOffset($ts) {
		$Offset = date("O", $ts);

		$Parity = $Offset < 0 ? -1 : 1;
		$Offset = $Parity * $Offset;
		$Offset = ($Offset - ($Offset % 100)) / 100 * 60 + $Offset % 100;

		return $Parity * $Offset;
	}

	/**
	 * gmtime() doesn't exist in standard PHP, so we have to implement it ourselves.
	 *
	 * @author Steve Hardy
	 *
	 * @param int $time
	 *
	 * @return array GMT Time
	 */
	public function gmtime($time) {
		$TZOffset = $this->GetTZOffset($time);

		$t_time = $time - $TZOffset * 60; # Counter adjust for localtime()

		return localtime($t_time, 1);
	}

	/**
	 * @param float|string $year
	 */
	public function isLeapYear($year): bool {
		return $year % 4 == 0 && ($year % 100 != 0 || $year % 400 == 0);
	}

	/**
	 * @param float|string $year
	 * @param int|string   $month
	 */
	public function getMonthInSeconds($year, $month): int {
		if (in_array($month, [1, 3, 5, 7, 8, 10, 12])) {
			$day = 31;
		}
		elseif (in_array($month, [4, 6, 9, 11])) {
			$day = 30;
		}
		else {
			$day = 28;
			if ($this->isLeapYear($year) == 1) {
				++$day;
			}
		}

		return $day * 24 * 60 * 60;
	}

	/**
	 * Function to get a date by Year Nr, Month Nr, Week Nr, Day Nr, and hour.
	 *
	 * @param int $year
	 * @param int $month
	 * @param int $week
	 * @param int $day
	 * @param int $hour
	 *
	 * @return int the timestamp of the given date, timezone-independent
	 */
	public function getDateByYearMonthWeekDayHour($year, $month, $week, $day, $hour) {
		// get first day of month
		$date = gmmktime(0, 0, 0, $month, 0, $year + 1900);

		// get wday info
		$gmdate = $this->gmtime($date);

		$date -= $gmdate["tm_wday"] * 24 * 60 * 60; // back up to start of week

		$date += $week * 7 * 24 * 60 * 60; // go to correct week nr
		$date += $day * 24 * 60 * 60;
		$date += $hour * 60 * 60;

		$gmdate = $this->gmtime($date);

		// if we are in the next month, then back up a week, because week '5' means
		// 'last week of month'

		if ($month != $gmdate["tm_mon"] + 1) {
			$date -= 7 * 24 * 60 * 60;
		}

		return $date;
	}

	/**
	 * getTimezone gives the timezone offset (in minutes) of the given
	 * local date/time according to the given TZ info.
	 *
	 * @param mixed $tz
	 * @param mixed $date
	 */
	public function getTimezone($tz, $date) {
		// No timezone -> GMT (+0)
		if (!isset($tz["timezone"])) {
			return 0;
		}

		$dst = false;
		$gmdate = $this->gmtime($date);

		$dststart = $this->getDateByYearMonthWeekDayHour($gmdate["tm_year"], $tz["dststartmonth"], $tz["dststartweek"], 0, $tz["dststarthour"]);
		$dstend = $this->getDateByYearMonthWeekDayHour($gmdate["tm_year"], $tz["dstendmonth"], $tz["dstendweek"], 0, $tz["dstendhour"]);

		if ($dststart <= $dstend) {
			// Northern hemisphere, eg DST is during Mar-Oct
			if ($date > $dststart && $date < $dstend) {
				$dst = true;
			}
		}
		else {
			// Southern hemisphere, eg DST is during Oct-Mar
			if ($date < $dstend || $date > $dststart) {
				$dst = true;
			}
		}

		if ($dst) {
			return $tz["timezone"] + $tz["timezonedst"];
		}

		return $tz["timezone"];
	}

	/**
	 * getWeekNr() returns the week nr of the month (ie first week of february is 1).
	 *
	 * @param mixed $date
	 *
	 * @return float|int
	 */
	public function getWeekNr($date) {
		$gmdate = gmtime($date);
		$gmdate["tm_mday"] = 0;

		return strftime("%W", $date) - strftime("%W", gmmktime($gmdate)) + 1;
	}

	/**
	 * parseTimezone parses the timezone as specified in named property 0x8233
	 * in Outlook calendar messages. Returns the timezone in minutes negative
	 * offset (GMT +2:00 -> -120).
	 *
	 * @param mixed $data
	 *
	 * @return null|array|false
	 */
	public function parseTimezone($data) {
		if (strlen($data) < 48) {
			return;
		}

		return unpack("ltimezone/lunk/ltimezonedst/lunk/ldstendmonth/vdstendweek/vdstendhour/lunk/lunk/vunk/ldststartmonth/vdststartweek/vdststarthour/lunk/vunk", $data);
	}

	/**
	 * @param mixed $tz
	 *
	 * @return false|string
	 */
	public function getTimezoneData($tz) {
		return pack("lllllvvllvlvvlv", $tz["timezone"], 0, $tz["timezonedst"], 0, $tz["dstendmonth"], $tz["dstendweek"], $tz["dstendhour"], 0, 0, 0, $tz["dststartmonth"], $tz["dststartweek"], $tz["dststarthour"], 0, 0);
	}

	/**
	 * createTimezone creates the timezone as specified in the named property 0x8233
	 * see also parseTimezone()
	 * $tz is an array with the timezone data.
	 *
	 * @param mixed $tz
	 *
	 * @return false|string
	 */
	public function createTimezone($tz) {
		return pack(
			"lxxxxlxxxxlvvxxxxxxxxxxlvvxxxxxx",
			$tz["timezone"],
			array_key_exists("timezonedst", $tz) ? $tz["timezonedst"] : 0,
			array_key_exists("dstendmonth", $tz) ? $tz["dstendmonth"] : 0,
			array_key_exists("dstendweek", $tz) ? $tz["dstendweek"] : 0,
			array_key_exists("dstendhour", $tz) ? $tz["dstendhour"] : 0,
			array_key_exists("dststartmonth", $tz) ? $tz["dststartmonth"] : 0,
			array_key_exists("dststartweek", $tz) ? $tz["dststartweek"] : 0,
			array_key_exists("dststarthour", $tz) ? $tz["dststarthour"] : 0
		);
	}

	/**
	 * toGMT returns a timestamp in GMT time for the time and timezone given.
	 *
	 * @param mixed $tz
	 * @param mixed $date
	 */
	public function toGMT($tz, $date) {
		if (!isset($tz['timezone'])) {
			return $date;
		}
		$offset = $this->getTimezone($tz, $date);

		return $date + $offset * 60;
	}

	/**
	 * fromGMT returns a timestamp in the local timezone given from the GMT time given.
	 *
	 * @param mixed $tz
	 * @param mixed $date
	 */
	public function fromGMT($tz, $date) {
		$offset = $this->getTimezone($tz, $date);

		return $date - $offset * 60;
	}

	/**
	 * Function to get timestamp of the beginning of the day of the timestamp given.
	 *
	 * @param mixed $date
	 *
	 * @return false|int timestamp referring to same day but at 00:00:00
	 */
	public function dayStartOf($date) {
		$time1 = $this->gmtime($date);

		return gmmktime(0, 0, 0, $time1["tm_mon"] + 1, $time1["tm_mday"], $time1["tm_year"] + 1900);
	}

	/**
	 * Function to get timestamp of the beginning of the month of the timestamp given.
	 *
	 * @param mixed $date
	 *
	 * @return false|int Timestamp referring to same month but on the first day, and at 00:00:00
	 */
	public function monthStartOf($date) {
		$time1 = $this->gmtime($date);

		return gmmktime(0, 0, 0, $time1["tm_mon"] + 1, 1, $time1["tm_year"] + 1900);
	}

	/**
	 * Function to get timestamp of the beginning of the year of the timestamp given.
	 *
	 * @param mixed $date
	 *
	 * @return false|int Timestamp referring to the same year but on Jan 01, at 00:00:00
	 */
	public function yearStartOf($date) {
		$time1 = $this->gmtime($date);

		return gmmktime(0, 0, 0, 1, 1, $time1["tm_year"] + 1900);
	}

	/**
	 * Function which returns the items in a given interval. This included expansion of the recurrence and
	 * processing of exceptions (modified and deleted).
	 *
	 * @param int   $start         start time of the interval (GMT)
	 * @param int   $end           end time of the interval (GMT)
	 * @param mixed $limit
	 * @param mixed $remindersonly
	 *
	 * @return (array|mixed)[]
	 *
	 * @psalm-return array<int, T|array>
	 */
	public function getItems($start, $end, $limit = 0, $remindersonly = false): array {
		$items = [];

		if (isset($this->recur)) {
			// Optimization: remindersonly and default reminder is off; since only exceptions with reminder set will match, just look which
			// exceptions are in range and have a reminder set
			if ($remindersonly && (!isset($this->messageprops[$this->proptags["reminder"]]) || $this->messageprops[$this->proptags["reminder"]] == false)) {
				// Sort exceptions by start time
				uasort($this->recur["changed_occurrences"], [$this, "sortExceptionStart"]);

				// Loop through all changed exceptions
				foreach ($this->recur["changed_occurrences"] as $exception) {
					// Check reminder set
					if (!isset($exception["reminder"]) || $exception["reminder"] == false) {
						continue;
					}

					// Convert to GMT
					$occstart = $this->toGMT($this->tz, $exception["start"]);
					$occend = $this->toGMT($this->tz, $exception["end"]);

					// Check range criterium
					if ($occstart > $end || $occend < $start) {
						continue;
					}

					// OK, add to items.
					array_push($items, $this->getExceptionProperties($exception));
					if ($limit && (count($items) == $limit)) {
						break;
					}
				}

				uasort($items, [$this, "sortStarttime"]);

				return $items;
			}

			// From here on, the dates of the occurrences are calculated in local time, so the days we're looking
			// at are calculated from the local time dates of $start and $end

			if (isset($this->recur['regen'], $this->action['datecompleted']) && $this->recur['regen']) {
				$daystart = $this->dayStartOf($this->action['datecompleted']);
			}
			else {
				$daystart = $this->dayStartOf($this->recur["start"]); // start on first day of occurrence
			}

			// Calculate the last day on which we want to be looking at a recurrence; this is either the end of the view
			// or the end of the recurrence, whichever comes first
			if ($end > $this->toGMT($this->tz, $this->recur["end"])) {
				$rangeend = $this->toGMT($this->tz, $this->recur["end"]);
			}
			else {
				$rangeend = $end;
			}

			$dayend = $this->dayStartOf($this->fromGMT($this->tz, $rangeend));

			// Loop through the entire recurrence range of dates, and check for each occurrence whether it is in the view range.
			$recurType = (int) $this->recur["type"] < 0x2000 ? (int) $this->recur["type"] + 0x2000 : (int) $this->recur["type"];

			switch ($recurType) {
				case IDC_RCEV_PAT_ORB_DAILY:
					if ($this->recur["everyn"] <= 0) {
						$this->recur["everyn"] = 1440;
					}

					if ($this->recur["subtype"] == rptDay) {
						// Every Nth day
						for ($now = $daystart; $now <= $dayend && ($limit == 0 || count($items) < $limit); $now += 60 * $this->recur["everyn"]) {
							$this->processOccurrenceItem($items, $start, $end, $now, $this->recur["startocc"], $this->recur["endocc"], $this->tz, $remindersonly);
						}
					}
					else {
						// Every workday
						for ($now = $daystart; $now <= $dayend && ($limit == 0 || count($items) < $limit); $now += 60 * 1440) {
							$nowtime = $this->gmtime($now);
							if ($nowtime["tm_wday"] > 0 && $nowtime["tm_wday"] < 6) { // only add items in the given timespace
								$this->processOccurrenceItem($items, $start, $end, $now, $this->recur["startocc"], $this->recur["endocc"], $this->tz, $remindersonly);
							}
						}
					}
					break;

				case IDC_RCEV_PAT_ORB_WEEKLY:
					if ($this->recur["everyn"] <= 0) {
						$this->recur["everyn"] = 1;
					}

					// If sliding flag is set then move to 'n' weeks
					if ($this->recur['regen']) {
						$daystart += (60 * 60 * 24 * 7 * $this->recur["everyn"]);
					}

					for ($now = $daystart; $now <= $dayend && ($limit == 0 || count($items) < $limit); $now += (60 * 60 * 24 * 7 * $this->recur["everyn"])) {
						if ($this->recur['regen']) {
							$this->processOccurrenceItem($items, $start, $end, $now, $this->recur["startocc"], $this->recur["endocc"], $this->tz, $remindersonly);
						}
						else {
							// Loop through the whole following week to the first occurrence of the week, add each day that is specified
							for ($wday = 0; $wday < 7; ++$wday) {
								$daynow = $now + $wday * 60 * 60 * 24;
								// checks weather the next coming day in recurring pattern is less than or equal to end day of the recurring item
								if ($daynow <= $dayend) {
									$nowtime = $this->gmtime($daynow); // Get the weekday of the current day
									if ($this->recur["weekdays"] & (1 << $nowtime["tm_wday"])) { // Selected ?
										$this->processOccurrenceItem($items, $start, $end, $daynow, $this->recur["startocc"], $this->recur["endocc"], $this->tz, $remindersonly);
									}
								}
							}
						}
					}
					break;

				case IDC_RCEV_PAT_ORB_MONTHLY:
					if ($this->recur["everyn"] <= 0) {
						$this->recur["everyn"] = 1;
					}

					// Loop through all months from start to end of occurrence, starting at beginning of first month
					for ($now = $this->monthStartOf($daystart); $now <= $dayend && ($limit == 0 || count($items) < $limit); $now += $this->daysInMonth($now, $this->recur["everyn"]) * 24 * 60 * 60) {
						if (isset($this->recur["monthday"]) && ($this->recur['monthday'] != "undefined") && !$this->recur['regen']) { // Day M of every N months
							$difference = 1;
							if ($this->daysInMonth($now, $this->recur["everyn"]) < $this->recur["monthday"]) {
								$difference = $this->recur["monthday"] - $this->daysInMonth($now, $this->recur["everyn"]) + 1;
							}
							$daynow = $now + (($this->recur["monthday"] - $difference) * 24 * 60 * 60);
							// checks weather the next coming day in recurrence pattern is less than or equal to end day of the recurring item
							if ($daynow <= $dayend) {
								$this->processOccurrenceItem($items, $start, $end, $daynow, $this->recur["startocc"], $this->recur["endocc"], $this->tz, $remindersonly);
							}
						}
						elseif (isset($this->recur["nday"], $this->recur["weekdays"])) { // Nth [weekday] of every N months
							// Sanitize input
							if ($this->recur["weekdays"] == 0) {
								$this->recur["weekdays"] = 1;
							}

							// If nday is not set to the last day in the month
							if ($this->recur["nday"] < 5) {
								// keep the track of no. of time correct selection pattern (like 2nd weekday, 4th friday, etc.) is matched
								$ndaycounter = 0;
								// Find matching weekday in this month
								for ($day = 0, $total = $this->daysInMonth($now, 1); $day < $total; ++$day) {
									$daynow = $now + $day * 60 * 60 * 24;
									$nowtime = $this->gmtime($daynow); // Get the weekday of the current day

									if ($this->recur["weekdays"] & (1 << $nowtime["tm_wday"])) { // Selected ?
										++$ndaycounter;
									}
									// check the selected pattern is same as asked Nth weekday,If so set the firstday
									if ($this->recur["nday"] == $ndaycounter) {
										$firstday = $day;
										break;
									}
								}
								// $firstday is the day of the month on which the asked pattern of nth weekday matches
								$daynow = $now + $firstday * 60 * 60 * 24;
							}
							else {
								// Find last day in the month ($now is the firstday of the month)
								$NumDaysInMonth = $this->daysInMonth($now, 1);
								$daynow = $now + (($NumDaysInMonth - 1) * 24 * 60 * 60);

								$nowtime = $this->gmtime($daynow);
								while (($this->recur["weekdays"] & (1 << $nowtime["tm_wday"])) == 0) {
									$daynow -= 86400;
									$nowtime = $this->gmtime($daynow);
								}
							}

							/*
							* checks weather the next coming day in recurrence pattern is less than or equal to end day of the			* recurring item.Also check weather the coming day in recurrence pattern is greater than or equal to start * of recurring pattern, so that appointment that fall under the recurrence range are only displayed.
							*/
							if ($daynow <= $dayend && $daynow >= $daystart) {
								$this->processOccurrenceItem($items, $start, $end, $daynow, $this->recur["startocc"], $this->recur["endocc"], $this->tz, $remindersonly);
							}
						}
						elseif ($this->recur['regen']) {
							$next_month_start = $now + ($this->daysInMonth($now, 1) * 24 * 60 * 60);
							$now = $daystart + ($this->daysInMonth($next_month_start, $this->recur['everyn']) * 24 * 60 * 60);

							if ($now <= $dayend) {
								$this->processOccurrenceItem($items, $daystart, $end, $now, $this->recur["startocc"], $this->recur["endocc"], $this->tz, $remindersonly);
							}
						}
					}
					break;

				case IDC_RCEV_PAT_ORB_YEARLY:
					if ($this->recur["everyn"] <= 0) {
						$this->recur["everyn"] = 12;
					}

					for ($now = $this->yearStartOf($daystart); $now <= $dayend && ($limit == 0 || count($items) < $limit); $now += $this->daysInMonth($now, $this->recur["everyn"]) * 24 * 60 * 60) {
						if (isset($this->recur["monthday"]) && !$this->recur['regen']) { // same as monthly, but in a specific month
							// recur["month"] is in minutes since the beginning of the year
							$month = $this->monthOfYear($this->recur["month"]); // $month is now month of year [0..11]
							$monthday = $this->recur["monthday"]; // $monthday is day of the month [1..31]
							$monthstart = $now + $this->daysInMonth($now, $month) * 24 * 60 * 60; // $monthstart is the timestamp of the beginning of the month
							if ($monthday > $this->daysInMonth($monthstart, 1)) {
								$monthday = $this->daysInMonth($monthstart, 1);
							}	// Cap $monthday on month length (eg 28 feb instead of 29 feb)
							$daynow = $monthstart + ($monthday - 1) * 24 * 60 * 60;
							$this->processOccurrenceItem($items, $start, $end, $daynow, $this->recur["startocc"], $this->recur["endocc"], $this->tz, $remindersonly);
						}
						elseif (isset($this->recur["nday"], $this->recur["weekdays"])) { // Nth [weekday] in month X of every N years
							// Go the correct month
							$monthnow = $now + $this->daysInMonth($now, $this->monthOfYear($this->recur["month"])) * 24 * 60 * 60;

							// Find first matching weekday in this month
							for ($wday = 0; $wday < 7; ++$wday) {
								$daynow = $monthnow + $wday * 60 * 60 * 24;
								$nowtime = $this->gmtime($daynow); // Get the weekday of the current day

								if ($this->recur["weekdays"] & (1 << $nowtime["tm_wday"])) { // Selected ?
									$firstday = $wday;
									break;
								}
							}

							// Same as above (monthly)
							$daynow = $monthnow + ($firstday + ($this->recur["nday"] - 1) * 7) * 60 * 60 * 24;

							while ($this->monthStartOf($daynow) != $this->monthStartOf($monthnow)) {
								$daynow -= 7 * 60 * 60 * 24;
							}

							$this->processOccurrenceItem($items, $start, $end, $daynow, $this->recur["startocc"], $this->recur["endocc"], $this->tz, $remindersonly);
						}
						elseif ($this->recur['regen']) {
							$year_starttime = $this->gmtime($now);
							$is_next_leapyear = $this->isLeapYear($year_starttime['tm_year'] + 1900 + 1);	// +1 next year
							$now = $daystart + ($is_next_leapyear ? 31622400 /* Leap year in seconds */ : 31536000 /* year in seconds */);

							if ($now <= $dayend) {
								$this->processOccurrenceItem($items, $daystart, $end, $now, $this->recur["startocc"], $this->recur["endocc"], $this->tz, $remindersonly);
							}
						}
					}
			}
			// to get all exception items
			if (!empty($this->recur['changed_occurrences'])) {
				$this->processExceptionItems($items, $start, $end);
			}
		}

		// sort items on starttime
		usort($items, [$this, "sortStarttime"]);

		// Return the MAPI-compatible list of items for this object
		return $items;
	}

	/**
	 * @psalm-return -1|0|1
	 *
	 * @param mixed $a
	 * @param mixed $b
	 */
	public function sortStarttime($a, $b): int {
		$aTime = $a[$this->proptags["startdate"]];
		$bTime = $b[$this->proptags["startdate"]];

		return $aTime == $bTime ? 0 : ($aTime > $bTime ? 1 : -1);
	}

	/**
	 * daysInMonth.
	 *
	 * Returns the number of days in the upcoming number of months. If you specify 1 month as
	 * $months it will give you the number of days in the month of $date. If you specify more it
	 * will also count the days in the upcoming months and add that to the number of days. So
	 * if you have a date in march and you specify $months as 2 it will return 61.
	 *
	 * @param int $date   specified date as timestamp from which you want to know the number
	 *                    of days in the month
	 * @param int $months number of months you want to know the number of days in
	 *
	 * @return float|int number of days in the specified amount of months
	 */
	public function daysInMonth($date, $months) {
		$days = 0;

		for ($i = 0; $i < $months; ++$i) {
			$days += date("t", $date + $days * 24 * 60 * 60);
		}

		return $days;
	}

	// Converts MAPI-style 'minutes' into the month of the year [0..11]
	public function monthOfYear($minutes) {
		$d = gmmktime(0, 0, 0, 1, 1, 2001); // The year 2001 was a non-leap year, and the minutes provided are always in non-leap-year-minutes

		$d += $minutes * 60;

		$dtime = $this->gmtime($d);

		return $dtime["tm_mon"];
	}

	/**
	 * @psalm-return -1|0|1
	 *
	 * @param mixed $a
	 * @param mixed $b
	 */
	public function sortExceptionStart($a, $b): int {
		return $a["start"] == $b["start"] ? 0 : ($a["start"] > $b["start"] ? 1 : -1);
	}

	/**
	 * Function to get all properties of a single changed exception.
	 *
	 * @param mixed $exception
	 *
	 * @return (mixed|true)[] associative array of properties for the exception
	 *
	 * @psalm-return array<mixed|true>
	 */
	public function getExceptionProperties($exception): array {
		// Exception has same properties as main object, with some properties overridden:
		$item = $this->messageprops;

		// Special properties
		$item["exception"] = true;
		$item["basedate"] = $exception["basedate"]; // note that the basedate is always in local time !

		// MAPI-compatible properties (you can handle an exception as a normal calendar item like this)
		$item[$this->proptags["startdate"]] = $this->toGMT($this->tz, $exception["start"]);
		$item[$this->proptags["duedate"]] = $this->toGMT($this->tz, $exception["end"]);
		$item[$this->proptags["commonstart"]] = $item[$this->proptags["startdate"]];
		$item[$this->proptags["commonend"]] = $item[$this->proptags["duedate"]];

		if (isset($exception["subject"])) {
			$item[$this->proptags["subject"]] = $exception["subject"];
		}

		if (isset($exception["label"])) {
			$item[$this->proptags["label"]] = $exception["label"];
		}

		if (isset($exception["alldayevent"])) {
			$item[$this->proptags["alldayevent"]] = $exception["alldayevent"];
		}

		if (isset($exception["location"])) {
			$item[$this->proptags["location"]] = $exception["location"];
		}

		if (isset($exception["remind_before"])) {
			$item[$this->proptags["reminder_minutes"]] = $exception["remind_before"];
		}

		if (isset($exception["reminder_set"])) {
			$item[$this->proptags["reminder"]] = $exception["reminder_set"];
		}

		if (isset($exception["busystatus"])) {
			$item[$this->proptags["busystatus"]] = $exception["busystatus"];
		}

		return $item;
	}

	/**
	 * @param false|int $start
	 * @param false|int $basedate
	 * @param mixed     $startocc
	 * @param mixed     $endocc
	 * @param mixed     $tz
	 * @param mixed     $reminderonly
	 */
	abstract public function processOccurrenceItem(array &$items, $start, int $end, $basedate, $startocc, $endocc, $tz, $reminderonly);
}
