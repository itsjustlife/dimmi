# Wikipedia Topics Overlay

This branch mirrors the top layer of [Wikipedia's contents structure](https://en.wikipedia.org/wiki/Wikipedia:Contents) so the Arkhive (and Door) can reuse familiar navigation categories. Each folder below introduces one high-level topic area. You can drill down by adding Markdown notes, outlines, or further folders inside each area.

## How it fits the Mind Atlas
- **Alignment with What** – These folders live under the `WHAT` branch because they organise knowledge domains and subject matter.
- **Bridges to other branches** – When adding details, link to relevant WHO, WHERE, WHEN, WHY, or HOW entries so Door surfaces cross-branch context.
- **Security / access** – Door’s node builder reads any Markdown inside `ARKHIVE/` (except README duplicates). Using this directory keeps permissions consistent with the rest of the Arkhive tree; no extra configuration is needed.

## Top-level topic areas
- [General Reference](General_Reference/README.md)
- [Culture and the Arts](Culture_and_the_Arts/README.md)
- [Geography and Places](Geography_and_Places/README.md)
- [Health and Medicine](Health_and_Medicine/README.md)
- [History and Events](History_and_Events/README.md)
- [Human Activities](Human_Activities/README.md)
- [Mathematics and Logic](Mathematics_and_Logic/README.md)
- [Natural and Physical Sciences](Natural_and_Physical_Sciences/README.md)
- [People and Self](People_and_Self/README.md)
- [Philosophy and Thinking](Philosophy_and_Thinking/README.md)
- [Religion and Belief Systems](Religion_and_Belief_Systems/README.md)
- [Society and Social Sciences](Society_and_Social_Sciences/README.md)
- [Technology and Applied Sciences](Technology_and_Applied_Sciences/README.md)

Add new folders or notes under whichever heading fits best. Door will pick them up automatically the next time we rebuild its node cache.
