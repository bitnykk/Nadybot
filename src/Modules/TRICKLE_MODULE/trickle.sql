DROP TABLE IF EXISTS trickle;
CREATE TABLE trickle (
	id INT NOT NULL PRIMARY KEY,
	groupName VARCHAR(20) NOT NULL,
	name VARCHAR(30) NOT NULL,
	amountAgi DECIMAL(3,1) NOT NULL,
	amountInt DECIMAL(3,1) NOT NULL,
	amountPsy DECIMAL(3,1) NOT NULL,
	amountSta DECIMAL(3,1) NOT NULL,
	amountStr DECIMAL(3,1) NOT NULL,
	amountSen DECIMAL(3,1) NOT NULL
);

INSERT INTO trickle (id, groupName, name, amountAgi, amountInt, amountPsy, amountSta, amountStr, amountSen) VALUES
(1, 'Body & Defense', 'Body Dev.', 0, 0, 0, 1, 0, 0),
(2, 'Body & Defense', 'Nano Pool', 0, .1, .7, .1, 0, .1),
(3, 'Body & Defense', 'Evade-ClsC', .5, .2, 0, 0, 0, .3),
(4, 'Body & Defense', 'Dodge-Rng', .5, .2, 0, 0, 0, .3),
(5, 'Body & Defense', 'Duck-Exp', .5, .2, 0, 0, 0, .3),
(6, 'Body & Defense', 'Nano Resist', 0, .2, .8, 0, 0, 0),
(7, 'Body & Defense', 'Deflect', .2, 0, 0, 0, .5, .3),

(8, 'Melee Weapons', '1h Blunt', .1, 0, 0, .4, .5, 0),
(9, 'Melee Weapons', '1h Edged', .4, 0, 0, .3, .3, 0),
(10, 'Melee Weapons', 'Piercing', .5, 0, 0, .3, .2, 0),
(11, 'Melee Weapons', '2h Blunt', 0, 0, 0, .5, .5, 0),
(12, 'Melee Weapons', '2h Edged', 0, 0, 0, .4, .6, 0),
(13, 'Melee Weapons', 'Melee Ener.', 0, .5, 0, .5, 0, 0),
(14, 'Melee Weapons', 'Martial Arts', .5, 0, .3, 0, .2, 0),
(15, 'Melee Weapons', 'Multi. Melee', .6, 0, 0, .1, .3, 0),
(16, 'Melee Weapons', 'Melee. Init.', .1, .1, .2, 0, 0, .6),
(17, 'Melee Weapons', 'Physic. Init', .1, .1, .2, 0, 0, .6),

(18, 'Melee Specials', 'Sneak Atck', 0.5, 0.3, 0, 0, 0, .2),
(19, 'Melee Specials', 'Brawling', 0, 0, 0, .4, .6, 0),
(20, 'Melee Specials', 'Fast Attack', .6, 0, 0, 0, 0, .4),
(21, 'Melee Specials', 'Dimach', 0, 0, .2, 0, 0, .8),
(22, 'Melee Specials', 'Riposte', .5, 0, 0, 0, 0, .5),

(23, 'Ranged Weapons', 'Pistol', .6, 0, 0, 0, 0, .4),
(24, 'Ranged Weapons', 'Bow', .4, 0, 0, 0, .2, .4),
(25, 'Ranged Weapons', 'MG / SMG', .3, 0, 0, .3, .3, .1),
(26, 'Ranged Weapons', 'Assault Rif', .3, 0, 0, .4, .1, .2),
(27, 'Ranged Weapons', 'Shotgun', .6, 0, 0, 0, .4, 0),
(28, 'Ranged Weapons', 'Rifle', .6, 0, 0, 0, 0, .4),
(29, 'Ranged Weapons', 'Ranged Ener', 0, .2, .4, 0, 0, .4),
(30, 'Ranged Weapons', 'Grenade', .4, .2, 0, 0, 0, .4),
(31, 'Ranged Weapons', 'Heavy Weapons', .6, 0, 0, 0, .4, 0),
(32, 'Ranged Weapons', 'Multi Ranged', .6, .4, 0, 0, 0, 0),
(33, 'Ranged Weapons', 'Ranged. Init.', .1, .1, .2, 0, 0, .6),

(34, 'Ranged Specials', 'Fling Shot', 1, 0, 0, 0, 0, 0),
(35, 'Ranged Specials', 'Aimed Shot', 0, 0, 0, 0, 0, 1),
(36, 'Ranged Specials', 'Burst', .5, 0, 0, .2, .3, 0),
(37, 'Ranged Specials', 'Full Auto', 0, 0, 0, .4, .6, 0),
(38, 'Ranged Specials', 'Bow Spc Att', .5, 0, 0, 0, .1, .4),
(39, 'Ranged Specials', 'Sharp Obj', .6, 0, 0, 0, .2, .2),

(40, 'Nanos & Casting', 'Matt.Metam', 0, .8, .2, 0, 0, 0),
(41, 'Nanos & Casting', 'Bio Metamor', 0, .8, .2, 0, 0, 0),
(42, 'Nanos & Casting', 'Psycho Modi', 0, .8, 0, 0, 0, .2),
(43, 'Nanos & Casting', 'Sensory Impr', 0, .8, 0, 0, .2, 0),
(44, 'Nanos & Casting', 'Time&Space', .2, .8, 0, 0, 0, 0),
(45, 'Nanos & Casting', 'Matter Crea', 0, .8, 0, .2, 0, 0),
(46, 'Nanos & Casting', 'NanoC. Init.', .4, 0, 0, 0, 0, .6),

(47, 'Exploring', 'Vehicle Air', .2, .2, 0, 0, 0, .6),
(48, 'Exploring', 'Vehicle Ground', .2, .2, 0, 0, 0, .6),
(49, 'Exploring', 'Vehicle Water', .2, .2, 0, 0, 0, .6),
(50, 'Exploring', 'Run Speed', .4, 0, 0, .4, .2, 0),
(51, 'Exploring', 'Adventuring', .5, 0, 0, .3, .2, 0),

(52, 'Combat & Healing', 'Perception', 0, .3, 0, 0, 0, .7),
(53, 'Combat & Healing', 'Concealment', .3, 0, 0, 0, 0, .7),
(54, 'Combat & Healing', 'Psychology', 0, .5, 0, 0, 0, .5),
(55, 'Combat & Healing', 'Trap Disarm.', .2, .2, 0, 0, 0, .6),
(56, 'Combat & Healing', 'First Aid', .3, .3, 0, 0, 0, .4),
(57, 'Combat & Healing', 'Treatment', .3, .5, 0, 0, 0, .2),

(58, 'Trade & Repair', 'Mech. Engi', .5, .5, 0, 0, 0, 0),
(59, 'Trade & Repair', 'Elec. Engi', .3, .5, 0, .2, 0, 0),
(60, 'Trade & Repair', 'Quantum FT', 0, .5, .5, 0, 0, 0),
(61, 'Trade & Repair', 'Chemistry', 0, .5, 0, .5, 0, 0),
(62, 'Trade & Repair', 'Weapon Smt', 0, .5, 0, 0, .5, 0),
(63, 'Trade & Repair', 'Nano Progra', 0, 1, 0, 0, 0, 0),
(64, 'Trade & Repair', 'Tutoring', 0, .7, .1, 0, 0, .2),
(65, 'Trade & Repair', 'Break&Entry', .4, 0, .3, 0, 0, .3),
(66, 'Trade & Repair', 'Comp. Liter', 0, 1, 0, 0, 0, 0),
(67, 'Trade & Repair', 'Pharma Tech', .2, .8, 0, 0, 0, 0),

(68, 'Disabled / Legacy', 'Swimming', .2, 0, 0, .6, .2, 0),
(69, 'Disabled / Legacy', 'Map Navig.', 0, .4, .1, 0, 0, .5);