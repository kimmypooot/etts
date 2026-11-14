# Kiosk Lookup Data — Eligibility Titles & Places of Examination

Extracted from `views/kiosk/request_form.php` for migration into database tables.

## Where the data lives today

| Form field | `<option>` source | Used by |
|---|---|---|
| `titleExam` — *Title of Eligibility* | `views/partials/shared/_cert_eligibilities.php` (PHP array, 20 entries), loop at `request_form.php:648` | Certification / Authentication form |
| `examCntr` — *Place of Exam / Issuance* | `views/partials/shared/_exam_centers.php` (PHP array, 302 entries), loop at `request_form.php:663` | Certification / Authentication form |

Both partials are shared with the ESD and FO forms (`modals.php` / `fo_modals.php`), so a DB-backed replacement must serve all three UIs, not only the kiosk.

**Not hardcoded:** the SPELS eligibility cards/dropdown (`spels_other`) already read from `esd_casts_eligibility_tbl` (`request_form.php:50`) — no work needed there.

**Stored as free text:** the submitted value is the display string itself (`value="<?= $elig ?>"`), not an ID. Existing `esd_casts_records` / `esd_casts_issuance_requests` rows therefore hold these literal strings; a migration must keep the strings matching or backfill IDs.

---

## 1. Eligibility Titles (20)

| # | Title of Eligibility |
|---|---|
| 1 | Career Service Professional |
| 2 | Career Service Subprofessional |
| 3 | Fire Officer |
| 4 | Penology Officer |
| 5 | Police Officer |
| 6 | Basic Competency on Local Treasury |
| 7 | Professional Board Examination for Teachers |
| 8 | Presidential Decree No. 907 |
| 9 | Barangay Official |
| 10 | Memorandum Circular No. 11 |
| 11 | Barangay Health Worker |
| 12 | Barangay Nutrition Scholar |
| 13 | Electronic Data Processing Specialist |
| 14 | Sanggunian Member First Level |
| 15 | Sanggunian Member Second Level |
| 16 | Veteran Preference Rating |
| 17 | Career Service Professional (Preference Rating) |
| 18 | Career Service Subprofessional (Preference Rating) |
| 19 | Meat Inspection and Safety |
| 20 | Republic Act No. 1080 |

---

## 2. Places of Examination / Issuance (302)

Listed in the exact order the form renders them (the first entry `-` is a real value, not a placeholder; the placeholder option is `Select Exam Center`).

| # | Place of Exam / Issuance |
|---|---|
| 1 | - |
| 2 | Abra |
| 3 | Agoncillo, Batangas |
| 4 | Alabel, Sarangani |
| 5 | Alcantara, Romblon |
| 6 | Amulung, East Cagayan |
| 7 | Angeles City, Pampanga |
| 8 | Antipolo, Rizal |
| 9 | Aparri, Cagayan |
| 10 | Armm, Maguindanao |
| 11 | Atok, Benguet |
| 12 | Bacolod City, Negros Occidental |
| 13 | Bacolor, Pampanga |
| 14 | Bacoor City, Cavite |
| 15 | Bacoor, Cavite |
| 16 | Baguio City, Benguet |
| 17 | Balanga City, Bataan |
| 18 | Balanga, Bataan |
| 19 | Balayan, Batangas |
| 20 | Baler, Aurora |
| 21 | Bambang, Nueva Vizcaya |
| 22 | Banaue, Ifugao |
| 23 | Banayoyo, Ilocos Sur |
| 24 | Bangkok, Thailand |
| 25 | Bangued, Abra |
| 26 | Basco, Batanes |
| 27 | Batangas City, Batangas |
| 28 | Bayombong, Nueva Vizcaya |
| 29 | Bicutan, Taguig |
| 30 | BiÑan, Laguna |
| 31 | Binmaley, Pangasinan |
| 32 | Bislig City, Surigao Del Sur |
| 33 | Bislig, Surigao Del Sur |
| 34 | Boac, Marinduque |
| 35 | Bogo City, Cebu |
| 36 | Bongao, Tawi-tawi |
| 37 | Bontoc, Mt Province |
| 38 | Borongan City, East Samar |
| 39 | Borongan, East Samar |
| 40 | Botolan, Zambales |
| 41 | Brooke's Point, Palawan |
| 42 | Buenavista, Agusan Del Norte |
| 43 | Buenavista, Guimaras |
| 44 | Butuan City, Agusan Del Norte - Region 10 |
| 45 | Butuan City, Agusan Del Norte - Region 15 |
| 46 | Cabanatuan City, Nueva Ecija |
| 47 | Cabarroguis, Quirino |
| 48 | Cabuyao, Laguna |
| 49 | Cagayan De Oro City, Misamis Oriental |
| 50 | Cainta, Rizal |
| 51 | Cajidiocan, Romblon |
| 52 | Calaca, Batangas |
| 53 | Calamba, Laguna |
| 54 | Calapan City, Oriental Mindoro |
| 55 | Calapan, Oriental Mindoro |
| 56 | Calayan, Cagayan |
| 57 | Calbayog City, West Samar |
| 58 | Caloocan City |
| 59 | Caloocan City (south) |
| 60 | Calumpit, Bulacan |
| 61 | Candelaria, Quezon |
| 62 | Candon City, Ilocos Sur |
| 63 | Cantilan, Surigao Del Sur |
| 64 | Cantillan, Surigao Del Sur |
| 65 | Carasi, Ilocos Norte |
| 66 | Carcar City, Cebu |
| 67 | Casiguran, Aurora |
| 68 | Catanauan, Quezon |
| 69 | Catarman, North Samar |
| 70 | Catbalogan City, West Samar |
| 71 | Catbalogan, West Samar |
| 72 | Cauayan City, Isabela |
| 73 | Cavite |
| 74 | Cavite City, Cavite |
| 75 | Cebu City, Cebu |
| 76 | Central Office |
| 77 | Chicago, Illinois, Usa |
| 78 | City Of Cabadbaran, Agusan Del Norte |
| 79 | City Of Guihulngan, Negros Oriental |
| 80 | City Of Ilagan, Isabela |
| 81 | City Of Iligan, Lanao Del Norte - Barmm |
| 82 | City Of Iligan, Lanao Del Norte - Region 10 |
| 83 | City Of Malolos, Bulacan |
| 84 | City Of Manila |
| 85 | City Of San Fernando, Pampanga |
| 86 | City Of Tandag, Surigao Del Sur - Region 15 |
| 87 | City Of Valencia, Bukidnon |
| 88 | Compostela, Compostela Valley Province |
| 89 | Conner, Kalinga Apayao |
| 90 | Corcuera, Romblon |
| 91 | Coron, Palawan |
| 92 | Cotabato City, Armm |
| 93 | Cotabato City, Barmm |
| 94 | Cotabato City, Maguindanao |
| 95 | Culion, Palawan |
| 96 | Cuyo, Palawan |
| 97 | Daet, Camarines Norte |
| 98 | Dagupan City, Pangasinan |
| 99 | Danao City, Cebu |
| 100 | Dapa, Surigao Del Norte |
| 101 | Daraga, Albay |
| 102 | DasmariÑas City |
| 103 | Datu Odin Sinsuat, Maguindanao |
| 104 | Davao City, Davao Del Sur |
| 105 | Diffun, Quirino |
| 106 | Digos City, Davao Del Sur |
| 107 | Digos, Davao Del Sur |
| 108 | Dinapigue, Isabela |
| 109 | Dingras, Ilocos Norte |
| 110 | Dipaculao, Aurora |
| 111 | Dipolog City, Zamboanga Del Norte |
| 112 | Divilacan, Isabela |
| 113 | Doha, Qatar |
| 114 | Dumaguete City, Negros Oriental |
| 115 | General Nakar, Quezon |
| 116 | General Santos City |
| 117 | General Santos City, South Cotabato |
| 118 | Gloria, Oriental Mindoro |
| 119 | Gonzaga, Cagayan |
| 120 | Guiguinto, Bulacan |
| 121 | Hinatuan, Surigao Del Sur |
| 122 | Hong Kong |
| 123 | Iba, Zambales |
| 124 | Ilagan, Isabela |
| 125 | Iligan City, Lanao Del Norte |
| 126 | Iloilo City, Iloilo |
| 127 | Imus City, Cavite |
| 128 | Imus, Cavite |
| 129 | Indang, Cavite |
| 130 | Infanta, Quezon |
| 131 | Ipil, Zamboanga Sibuguey |
| 132 | Iriga City, Camarines Sur |
| 133 | Isabela City, Basilan |
| 134 | Isulan, Sultan Kudarat |
| 135 | Jolo, Sulu |
| 136 | Jordan, Guimaras |
| 137 | Kabankalan City, Negros Occidental |
| 138 | Kabugao, Kalinga Apayao |
| 139 | Kalibo, Aklan |
| 140 | Kalinga, Apayao |
| 141 | Kidapawan City, North Cotabato |
| 142 | Koronadal City |
| 143 | La Trinidad, Benguet |
| 144 | Lagawe, Ifugao |
| 145 | Lallo, Cagayan |
| 146 | Lamitan City, Basilan |
| 147 | Lamut, Ifugao |
| 148 | Langilang, Abra |
| 149 | Laoag City, Ilocos Norte |
| 150 | Larena, Siquijor |
| 151 | Las PiÑas City |
| 152 | Legaspi City |
| 153 | Legazpi City, Albay |
| 154 | Lemery, Batangas |
| 155 | Lian, Batangas |
| 156 | Lianga, Surigao Del Sur |
| 157 | Lingayen, Pangasinan |
| 158 | Lingig, Surigao Del Sur |
| 159 | Lipa City, Batangas |
| 160 | Looc, Occidental Mindoro |
| 161 | Looc, Romblon |
| 162 | Lopez, Quezon |
| 163 | Los Angeles, California |
| 164 | Los BaÑos, Laguna |
| 165 | Lubang, Occidental Mindoro |
| 166 | Lucban, Quezon |
| 167 | Lucena City, Quezon |
| 168 | Luna, Apayao |
| 169 | Maasin City, South Leyte |
| 170 | Maasin, South Leyte |
| 171 | Maconacon, Isabela |
| 172 | Maddela, Quirino |
| 173 | Maguindanao, Armm |
| 174 | Makati City |
| 175 | Malaybalay, Bukidnon |
| 176 | Malita, Davao Occidental |
| 177 | Malolos, Bulacan |
| 178 | Mambajao, Camiguin |
| 179 | Mamburao, Occidental Mindoro |
| 180 | Manabo, Abra |
| 181 | Mandaluyong City |
| 182 | Mangagoy, Surigao Del Sur |
| 183 | Manila |
| 184 | Marawi City, Lanao Del Sur |
| 185 | Maria Aurora, Aurora |
| 186 | Marikina City |
| 187 | Masbate City, Masbate |
| 188 | Masbate, Masbate |
| 189 | Mati City, Davao Oriental |
| 190 | Mati, Davao Oriental |
| 191 | Morong, Rizal |
| 192 | Mulanay, Quezon |
| 193 | Muntinlupa City |
| 194 | Musuan, Bukidnon |
| 195 | Nabua, Camarines Sur |
| 196 | Nabunturan, Compostela Valley Province |
| 197 | Nabunturan, Davao De Oro |
| 198 | Naga City, Camarines Sur |
| 199 | Narra, Palawan |
| 200 | Nasugbo, Batangas |
| 201 | Naval, Biliran |
| 202 | Odiongan, Romblon |
| 203 | Olongapo City, Zambales |
| 204 | Ormoc City, Leyte Province |
| 205 | Oroquieta City, Misamis Occidental |
| 206 | Ozamiz City, Misamis Occidental |
| 207 | Pagadian City, Zamboanga Del Sur |
| 208 | Pagbilao, Quezon |
| 209 | Pagudpud, Ilocos Norte |
| 210 | Palanan, Isabela |
| 211 | Palo, Leyte |
| 212 | Pangasinan |
| 213 | Paracelis, Mt Province |
| 214 | ParaÑaque City |
| 215 | Paris, France |
| 216 | Pasay City |
| 217 | Pasig City |
| 218 | Pasig, Rizal |
| 219 | Pili, Camarines Sur |
| 220 | Pinamalayan, Oriental Mindoro |
| 221 | Pinukpuk, Kalinga Apayao |
| 222 | Polilio, Quezon |
| 223 | Prosperidad, Agusan Del Sur |
| 224 | Puerto Princesa City, Palawan |
| 225 | Quezon City |
| 226 | Quirino, Isabela |
| 227 | Riyadh, Ksa |
| 228 | Rizal |
| 229 | Rizal, Occidental Mindoro |
| 230 | Romblon, Romblon |
| 231 | Rosario, Batangas |
| 232 | Roxas City, Capiz |
| 233 | Sablayan, Occidental Mindoro |
| 234 | Sagada, Mt Province |
| 235 | San Agustin, Romblon |
| 236 | San Fernando City, La Union |
| 237 | San Fernando, Pampanga |
| 238 | San Fernando, Romblon |
| 239 | San Francisco, Agusan Del Sur |
| 240 | San Francisco, California |
| 241 | San Francisco, Surigao Del Norte |
| 242 | San Jose City, Nueva Ecija |
| 243 | San Jose, Antique |
| 244 | San Jose, Dinagat Islands |
| 245 | San Jose, Occidental Mindoro |
| 246 | San Jose, Surigao Del Norte |
| 247 | San Luis, Aurora |
| 248 | San Pablo City, Laguna |
| 249 | Sanchez Mira, Cagayan |
| 250 | Santa Rosa City, Laguna |
| 251 | Santa Rosa, Nueva Ecija |
| 252 | Santiago City, Isabela |
| 253 | Santiago, Isabela |
| 254 | Shariff Aguak, Maguindanao |
| 255 | Sibalom, Antique |
| 256 | Sogod, Southern Leyte |
| 257 | Solana, Cagayan |
| 258 | Sorsogon City, Sorsogon |
| 259 | Sorsogon, Sorsogon |
| 260 | Sta Cruz, Laguna |
| 261 | Sta. Ana, Cagayan |
| 262 | Sta. Marcela, Apayao |
| 263 | Surigao City, Agusan Del Norte - Region 15 |
| 264 | Surigao City, Surigao Del Norte - Region 10 |
| 265 | Tabuk City, Kalinga Apayao |
| 266 | Tabuk, Kalinga Apayao |
| 267 | Tacloban City, Leyte Province |
| 268 | Tacurong City, Sultan Kudarat |
| 269 | Tacurong, Sultan Kudarat |
| 270 | Tadian, Mt Province |
| 271 | Tagaytay City, Cavite |
| 272 | Tagbilaran City, Bohol |
| 273 | Taguig City |
| 274 | Tagum City, Davao Del Norte |
| 275 | Tagum, Davao Del Norte |
| 276 | Talibon, Bohol |
| 277 | Talisay City, Cebu |
| 278 | Tanauan, Batangas |
| 279 | Tanay, Rizal |
| 280 | Tandag, Surigao Del Norte - Region 15 |
| 281 | Tandag, Surigao Del Sur - Region 11 |
| 282 | Tangub City, Misamis Occidental |
| 283 | Tanza, Cavite |
| 284 | Tarlac City, Tarlac |
| 285 | Tarlac, Tarlac |
| 286 | Tokyo, Japan |
| 287 | Toledo City, Cebu |
| 288 | Trece Martires City, Cavite |
| 289 | Trece Martirez City, Cavite |
| 290 | Trinidad, Bohol |
| 291 | Tuguegarao City, Cagayan |
| 292 | Tuguegarao, Cagayan |
| 293 | Upi, Maguindanao |
| 294 | Urdaneta City, Pangasinan |
| 295 | Valencia City, Bukidnon |
| 296 | Valenzuela City |
| 297 | Victoria, Oriental Mindoro |
| 298 | Vienna, Austria |
| 299 | Vigan City, Ilocos Sur |
| 300 | Virac, Catanduanes |
| 301 | Washington Dc, Usa |
| 302 | Zamboanga City, Zamboanga Del Sur |

---

## 3. Data-quality issues to resolve during migration

1. **Corrupted capitals** — title-casing was applied to uppercase `Ñ`, producing `BiÑan, Laguna`, `DasmariÑas City`, `Las PiÑas City`, `Los BaÑos, Laguna`, `ParaÑaque City`. Correct forms: `Biñan`, `Dasmariñas`, `Las Piñas`, `Los Baños`, `Parañaque`. Fixing means updating existing records too, or keeping an alias.
2. **Near-duplicates** (same place, different spelling) — e.g. `Bacoor City, Cavite` / `Bacoor, Cavite`; `Balanga City, Bataan` / `Balanga, Bataan`; `Bislig City` / `Bislig`; `Borongan City` / `Borongan`; `Calapan City` / `Calapan`; `Catbalogan City` / `Catbalogan`; `Cantilan` / `Cantillan`; `Trece Martires` / `Trece Martirez`; `Legaspi City` / `Legazpi City, Albay`; `Cavite` / `Cavite City, Cavite`; `Manila` / `City Of Manila`; `Malolos, Bulacan` / `City Of Malolos, Bulacan`; `Tarlac City` / `Tarlac`; `Tabuk City` / `Tabuk`. Decide canonical names and add an `is_active`/alias column rather than deleting rows still referenced by old records.
3. **Region-suffixed entries** (`- Region 10`, `- Region 15`, `- Barmm`, `Armm`) — the same city appears under different regions as the region boundaries changed. Consider a separate `region` column instead of embedding it in the name.
4. **Non-Philippine venues** — `Bangkok, Thailand`, `Chicago, Illinois, Usa`, `Doha, Qatar`, `Hong Kong`, `Los Angeles, California`, `Paris, France`, `Riyadh, Ksa`, `San Francisco, California`, `Tokyo, Japan`, `Vienna, Austria`, `Washington Dc, Usa`. Suggest a `country` column.
5. **Pseudo-entries** — `-` (no center) and `Central Office`. Consider modelling as NULL / a flagged row.
6. **Inconsistent casing** — `Del`, `De`, `Of`, `Usa`, `Ksa`, `Mt Province`, `Sta Cruz` vs `Sta. Ana`. Normalise on import.
7. **Apostrophe** — `Brooke's Point, Palawan` is stored with a straight apostrophe; ensure the table charset is utf8mb4 (see migration 002) and that output stays `htmlspecialchars`-escaped.
8. **Eligibility titles** that look like legal references (`Presidential Decree No. 907`, `Republic Act No. 1080`, `Memorandum Circular No. 11`) — also overlap conceptually with `esd_casts_eligibility_tbl` (SPELS codes). Decide whether this is a separate table or a `type` flag on the existing one. Note `esd_casts_eligibility_tbl` has no unique constraint on `elig_code`.

## 4. Suggested schema (starting point)

```sql
CREATE TABLE esd_casts_cert_eligibilities (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(150) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE esd_casts_exam_centers (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,
  country    VARCHAR(60)  NOT NULL DEFAULT 'Philippines',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Rollout order that avoids breaking the live forms: (1) create + seed tables from the lists above, (2) change the two partials to `SELECT … WHERE is_active=1 ORDER BY sort_order, title` while still returning the same plain string arrays (so all three forms and their callers keep working), (3) only then add an admin CRUD page, following `views/esd/eligibilities.php` as the template.
