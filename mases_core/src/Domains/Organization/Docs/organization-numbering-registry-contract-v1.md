# የOrganization የቅርንጫፍና የዲፓርትመንት ኮድ መዝገብ ውል v1.0

**ሁኔታ:** OWNER-APPROVED  
**የስርዓት ባለቤት:** ARNA OS  
**የባለቤትነት ድንበር:** Organization Domain  
**የሚመለከተው መዝገብ:** `BB` (ሁለት አሃዝ)

## 1. ዓላማና የባለቤትነት ድንበር

ይህ ውል የARNA ድርጅታዊ ክፍሎች ለሰነድ ቁጥር አጠቃቀም የሚያገለግለውን ቋሚ `BB` መለያ ይወስናል። Organization Domain የቅርንጫፍ፣ የዲፓርትመንት፣ የኮድ ምደባ፣ የታሪክ ጥበቃና የሕይወት ዑደት ባለቤት ነው። Documents Domain ይህን መዝገብ ይጠቀማል፤ የራሱን ተወዳዳሪ የድርጅት ኮድ መዝገብ አይፈጥርም።

ይህ መዝገብ በtenant ደረጃ የተለየ መሆን አለበት። የተለያዩ legal entity ዎች በአንድ tenant ውስጥ ተመሳሳይ `BB` ለተለያዩ የሥራ ክፍሎች መመደብ አይችሉም።

## 2. የBB ቅርጸት

`BB` በትክክል ሁለት አሃዞች ነው፦

- የመጀመሪያው አሃዝ (`1–9`) የቅርንጫፍ ኮድ ነው።
- ሁለተኛው አሃዝ (`1–9`) በዚያ ቅርንጫፍ ውስጥ የዲፓርትመንት ኮድ ነው።
- `00`፣ `01`፣ `10` እና ዜሮ የያዙ ሌሎች ኮዶች አይፈቀዱም።
- የመጀመሪያው አሃዝ የቅርንጫፍ መለያ በtenant ውስጥ ልዩ ነው።
- ሁለተኛው አሃዝ በተመሳሳይ ቅርንጫፍ ውስጥ ልዩ ነው።

የአቅም ገደብ፦ ቢበዛ 9 ቅርንጫፎች፣ በእያንዳንዱም ቢበዛ 9 ዲፓርትመንቶች። ይህን ቅርጸት ማስፋት የOwner የተለየ የቅርጸት ለውጥ ፈቃድ ይፈልጋል።

## 3. የኮድ ምደባ እና ታሪካዊ ጥበቃ

1. ኮድ የሚመደበው በተፈቀደ የOrganization ሂደት ብቻ ነው።
2. የተመደበ ኮድ ከተጠቀመ በኋላ አይመለስም፤ ክፍሉ ቢዘጋ፣ ቢቀየር ወይም ቢዋሃድ ኮዱ በታሪክ መዝገብ ውስጥ ይቆያል።
3. የተሰረዘ ወይም የተቋረጠ ኮድ ለሌላ ክፍል እንደገና አይመደብም።
4. መዝገቡ የመጀመሪያ ቀን፣ የመጨረሻ ቀን፣ ሁኔታ፣ የምደባ ምክንያትና የaudit መረጃ ይይዛል።
5. የአንድ ክፍል ስም ወይም የድርጅት መዋቅር መቀየር ቀድሞ የተሰጠውን ኮድ በዝምታ አይቀይርም።
6. የlegal entity ግንኙነት በOrganization መዝገብ ውስጥ ይጠበቃል፤ ነገር ግን `BB` ልዩነት በtenant ደረጃ ይጠበቃል።

## 4. ምሳሌዎች — ምደባ አይደሉም

ከታች ያሉት ምሳሌዎች የቅርጸቱን ትርጉም ለማሳየት ብቻ ናቸው፤ የARNA ትክክለኛ የቅርንጫፍ/ዲፓርትመንት ኮድ ምደባ አይደሉም።

| ምሳሌ ክፍል | ምሳሌ BB |
|---|---:|
| Head Office – Executive | 11 |
| Head Office – Finance | 12 |
| Head Office – Human Resources | 13 |
| Operations Branch – Administration | 21 |
| Operations Branch – Finance | 22 |
| Operations Branch – Procurement | 23 |

ትክክለኛ የARNA ኮዶች የሚፈጠሩት በተለየ የOrganization ምደባ ሂደት ነው።

## 5. የድርጅት እና የሌሎች ዘርፎች ግንኙነት

- **Organization:** የቅርንጫፍ/ዲፓርትመንት መዝገብ፣ `BB` ምደባ፣ ታሪካዊ መረጃና የክፍል ሕይወት ዑደት።
- **Documents:** የOrganization መዝገብን በመጠቀም ቁጥር ያወጣል፤ የBB ባለቤት አይደለም።
- **Finance:** የlegal entity መለያና ሁኔታ ባለቤት ነው።
- **Governance:** የሥልጣን ቦታ፣ ተጠባባቂ ሹመትና የማጽደቅ ሥልጣን ባለቤት ነው፤ እነዚህ የBB ክፍሎች አይደሉም።
- **Core:** አጠቃላይ tenant isolation፣ identity፣ authorization እና የቀን መቁጠሪያ አገልግሎቶችን ይሰጣል።

## 6. የደህንነትና የውሂብ ጥበቃ መስፈርቶች

- ሁሉም የመዝገብ ንባብና ለውጥ tenant-scoped መሆን አለበት።
- የBB ልዩነት በdatabase constraint እና በdomain validation መጠበቅ አለበት።
- የታሪክ መዝገብ በhard delete መጥፋት የለበትም።
- የኮድ ምደባ፣ ማቋረጥና የታሪክ ማስተካከያ በaudit መመዝገብ አለበት።
- የtenant ድንበር ጥሰት ወይም የኮድ ግጭት ሲኖር ስርዓቱ fail-closed መሆን አለበት።

## 7. የለውጥ ቁጥጥር

ይህ ውል v1.0 በOwner ጸድቋል። የቁጥር ቅርጸትን፣ የBB አሃዞችን ወይም የልዩነት ደንቦችን የሚቀይር ለውጥ የOwner ግልጽ ፈቃድና አዲስ የversion ቁጥር ይፈልጋል።

**የትግበራ ማስታወሻ:** ይህ ሰነድ የፖሊሲ ፈቃድን ይመዘግባል፤ በራሱ የsource code፣ migration፣ database ወይም runtime ትግበራ ተጠናቋል ማለት አይደለም።


## Implementation status — 2026-10-30

- OrganizationNumberingRegistryService is registered as an Organization-owned singleton for internal domain integration.
- Branch registration requires an active Organization unit of type branch; department registration requires an active department directly under the registered branch and an active legal entity belonging to the same tenant.
- Department codes must be retired before their branch code can be retired. Retired codes remain reserved and cannot be reallocated.
- Registration and retirement write append-only Organization audit events with actor, reason, before/after state, and timestamp.
- Department-to-legal-entity persistence uses a composite (tenant_id, legal_entity_id) foreign key to Finance's (tenant_id, id) key. Issuance resolution checks the tenant, active legal entity, active branch code, active department code, and active Organization unit.
- Numbering endpoints require the separate tenant-scoped organization.numbering.manage permission. The Documents domain must consume the service and must not write directly to registry tables.
- Contract tests cover singleton registration, HTTP authorization, audit/retirement ordering, and cross-tenant legal-entity rejection. The workflow includes those tests and an independent Organization PHP syntax job.
- Runtime acceptance remains pending until exact-head checks pass. A prior clean-install run failed in the unrelated Finance/Loan SQLite stage before Organization tests and MySQL migration verification ran; the Finance-owned duplicate journal_type migration issue is outside this Organization change set.
