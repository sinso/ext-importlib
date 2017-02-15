CREATE TABLE tx_importlib_history (
	import_name varchar(255) DEFAULT '' NOT NULL,
	table_name varchar(255) DEFAULT '' NOT NULL,
	uid int(11) NOT NULL,
	field_hashes text DEFAULT '' NOT NULL,

	PRIMARY KEY t3ver_oid (import_name, table_name, uid)
);