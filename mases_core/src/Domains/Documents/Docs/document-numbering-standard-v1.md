# የARNA የሰነድ ቁጥር እና የማውጣት መመዘኛ v1.0

**ሁኔታ:** OWNER-APPROVED  
**የባለቤትነት ድንበር:** Documents Domain  
**የተፈቀደ ቅርጸት:** `ዓርና/BB/TT/SSSS/YY`

## 1. ዓላማ

ይህ መመዘኛ በARNA የሚወጡ ኦፊሴላዊ ሰነዶች ቋሚ፣ ልዩ፣ ሊመረመር የሚችልና በታሪክ የማይደገም መለያ እንዲኖራቸው ይወስናል። ይህ የARNA ውስጣዊ የቁጥር መመዘኛ ነው፤ የኢትዮጵያ መንግሥት ወይም የሌላ ተቆጣጣሪ አካል የግዴታ ቅርጸት ነው ተብሎ አይቀርብም። የሕግና የዘርፍ ተገዢነት ግምገማ ከዚህ ውል ውጭ ይቀጥላል።

## 2. ቋሚ የቁጥር ቅርጸት

`ዓርና/BB/TT/SSSS/YY`

| ክፍል | ትርጉም | ደንብ |
|---|---|---|
| `ዓርና` | ቋሚ የARNA ቅድመ ቃል | አይቀየርም |
| `BB` | የቅርንጫፍና የዲፓርትመንት ኮድ | ሁለት አሃዝ፤ Organization ይመድባል |
| `TT` | የሰነድ ዓይነት ኮድ | ሁለት አሃዝ፤ Documents ይቆጣጠራል |
| `SSSS` | ተከታታይ ቁጥር | 0001–9999 |
| `YY` | የሙሉ የኢትዮጵያ ዓመት የመጨረሻ ሁለት አሃዞች | ሙሉ ዓመቱ በdatabase ይቀመጣል |

ምሳሌ፦ `ዓርና/12/01/0042/19`። ይህ ምሳሌ የቅርጸቱን ትርጉም ያሳያል፤ `12` እና `01` ለእውነተኛ የARNA ክፍል የተመደቡ ኮዶች ናቸው ማለት አይደለም።

## 3. የBB የባለቤትነት ደንብ

`BB` የOrganization Domain ባለቤትነት ነው። የመጀመሪያው አሃዝ የቅርንጫፍ መለያ (`1–9`) ሲሆን ሁለተኛው አሃዝ በዚያ ቅርንጫፍ ውስጥ የዲፓርትመንት መለያ (`1–9`) ነው። ዜሮ የያዙ ኮዶች አይፈቀዱም። የቅርንጫፍ ኮድ በtenant ውስጥ ልዩ ነው፤ የዲፓርትመንት ኮድ በቅርንጫፉ ውስጥ ልዩ ነው። ኮድ አንዴ ከተጠቀመ በኋላ አይመለስም። ሙሉ የምደባ ደንብና ታሪካዊ ጥበቃ በOrganization የBB መዝገብ ውል ውስጥ ተመዝግቧል።

## 4. የTT የሰነድ ዓይነት መዝገብ

ከዚህ በታች ያለው ምደባ በOwner ጸድቋል፦

| TT | የሰነድ ዓይነት | የውስጥ መለያ |
|---:|---|---|
| 01 | ደብዳቤ (Letter) | `LET` |
| 02 | ማስታወሻ (Memorandum) | `MEM` |
| 03 | የሕግ ሰነድ (Legal instrument) | `LEG` |
| 04 | ደረሰኝ/ኢንቮይስ (Invoice) | `INV` |
| 05 | ማረጋገጫ ወረቀት (Certificate) | `CER` |
| 06 | ውሳኔ (Resolution) | `RES` |
| 07 | ሰርኩላር (Circular) | `CIR` |
| 08 | ሪፖርት (Report) | `RPT` |
| 09 | ውል (Contract) | `CON` |
| 10 | ማስታወቂያ (Notice) | `NOT` |
| 11 | ፖሊሲ (Policy) | `POL` |

የሶስት ፊደል ውስጣዊ መለያዎች እንደ stable internal identifiers/compatibility aliases ይቆያሉ። የቁጥር ኮዶች ከተጠቀሙ በኋላ አይቀየሩም፤ የተቋረጠ የሰነድ ዓይነት ኮድም ለሌላ ዓይነት አይመደብም። አዲስ ዓይነት በተፈቀደ የregistry ለውጥ ብቻ ይጨመራል።

## 5. የተከታታይ ቁጥር ወሰን

Counter scope በትክክል እነዚህን ያካትታል፦

- tenant;
- legal entity;
- issuing organizational unit (`BB`);
- document type (`TT`);
- ሙሉ የኢትዮጵያ ዓመት (`EC year`)።

የGovernance position፣ የissuer ስም ወይም acting appointment የcounter dimension አይደሉም፤ እነዚህ የሥልጣን/የሰነድ ማስረጃ metadata ናቸው።

በዚህ ወሰን ውስጥ ተከታታይ ቁጥር ከ0001 ይጀምራል። 9999 ከደረሰ በኋላ በዚያው ወሰን ተጨማሪ ቁጥር አይመደብም፤ ስርዓቱ በግልጽ ስህተት ይቆማል። የቁጥር ክፍል መቀየር ወይም ወደ 0001 መመለስ በዝምታ አይፈቀድም።

## 6. የኢትዮጵያ ዓመት ምንጭ

- የኢትዮጵያ ዓመት በserver ብቻ ይወሰናል።
- በissuance ጊዜ አንድ timestamp በ`Africa/Addis_Ababa` ይያዛል።
- የሙሉ EC year ስሌት በplatform Ethiopian Calendar service ይከናወናል።
- Client የዓመት እሴት መምረጥ ወይም መላክ አይችልም።
- ሙሉ EC year በcounter scope እና በdatabase ይጠበቃል፤ `YY` በማሳያ ቁጥር ውስጥ ብቻ ይታያል።

## 7. የማውጣት፣ የtransaction እና የidempotency ደንቦች

1. Draft ሰነድ ኦፊሴላዊ ቁጥር አይኖረውም።
2. ቁጥር የሚመደበው ሰነዱ በተሳካ ሁኔታ official issuance ሲያጠናቅቅ ብቻ ነው።
3. Counter allocation፣ document creation እና issuance lifecycle event በአንድ atomic database transaction ውስጥ መፈጸም አለባቸው።
4. የcounter scope ልዩነት database unique constraint እና row-level locking በመጠቀም ይጠበቃል።
5. በtenant-scoped idempotency key ላይ ተመሳሳይ key/ተመሳሳይ payload የቀድሞውን persisted response ይመልሳል፤ ተመሳሳይ key/የተለየ payload conflict ይመልሳል።
6. የተሰጠ ቁጥር በvoid፣ revoke ወይም cancellation ምክንያት እንደገና አይጠቀምም።
7. Replacement document አዲስ ቁጥር ይቀበላል፤ ከመጀመሪያው ሰነድ ጋር ያለው ግንኙነት በlifecycle መዝገብ ይጠበቃል።
8. የtransaction ወይም issuance ክፍል ካልተሳካ ቁጥርና ሰነድ በከፊል መቀመጥ የለባቸውም።

## 8. የሚመጡ ሰነዶች

የውጭ ላኪ የሰነድ ቁጥር/reference በትክክል እንደተቀበለው ይጠበቃል። ይህ የውጭ ማጣቀሻ ከARNA የውስጥ incoming registration ID የተለየ ነው። የውጭ ማጣቀሻን በARNA የሚወጣ ቁጥር መተካት አይፈቀድም።

## 9. የቁጥር ቋሚነት እና ማሳያ

አንድ canonical document number በscreen፣ PDF፣ print፣ search፣ export እና verification ሁሉ ተመሳሳይ መሆን አለበት። የሚታየው ቁጥር ከተመዘገበው canonical value በተለየ መንገድ እንደገና መፍጠር የለበትም።

## 10. የDomain ባለቤትነት

| Domain | የኃላፊነት ድንበር |
|---|---|
| Organization | `BB` መዝገብ፣ ምደባና ታሪካዊ ጥበቃ |
| Documents | `TT` መዝገብ፣ canonical formatter፣ counters፣ issuance እና lifecycle |
| Finance | legal entity መለያና ሁኔታ |
| Governance | issuing authority፣ position፣ acting authority እና grants |
| Core | tenant isolation፣ identity፣ authorization እና shared calendar service |

## 11. የተግባር ማስታወሻ

ይህ መመዘኛ የOwner የፖሊሲ ፈቃድን ይመዘግባል። የsource code ለውጥ፣ migration ማስኬድ፣ database ለውጥ፣ CI/test ማረጋገጫ፣ deployment ወይም PR merge ተፈጽሟል ማለት አይደለም። እነዚህ በተለያዩ የimplementation/verification ደረጃዎች ይከናወናሉ።
