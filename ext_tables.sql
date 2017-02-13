CREATE TABLE tx_importlib_history (
	uid int(11) NOT NULL auto_increment,
	import_id varchar(255) DEFAULT '' NOT NULL,
	field_hashes text DEFAULT '' NOT NULL,
	table_name varchar(255) DEFAULT '' NOT NULL,

	PRIMARY KEY (uid)
);