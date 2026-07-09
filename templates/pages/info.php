<h1>Tracker Information</h1>

<h2>What is a BitTorrent tracker?</h2>
<p>A BitTorrent tracker is a server that helps BitTorrent clients communicate. It coordinates file transfers between users (peers) by tracking who is sharing a given torrent. The tracker does not store any files &mdash; only information about active swarm participants.</p>

<h2>What is OpenTracker?</h2>
<p>OpenTracker is a high-performance BitTorrent tracker software created by erdgeist. It is open-source, extremely fast, and minimalist. It can handle millions of connections with minimal resource usage.</p>

<h2>How does the tracker work?</h2>
<p>A BitTorrent client sends an "announce" request to the tracker with the torrent's info_hash. The tracker responds with a list of peers currently sharing or downloading the same torrent. The tracker only knows: the info_hash, the peer's IP address, and port.</p>

<h2>What data does the tracker store?</h2>
<p>The tracker only stores active swarms &mdash; a list of info_hashes and their associated peers (IP + port). This data is temporary and removed when a peer's session expires. No files, torrent names, or content are stored.</p>

<h2>Frequently Asked Questions</h2>
<div class="faq-item"><div class="faq-q">Can you remove an info_hash?</div><div class="faq-a">The tracker automatically removes swarms when all peers' sessions expire. We do not control which torrents are tracked.</div></div>
<div class="faq-item"><div class="faq-q">Can you see what content is behind a hash?</div><div class="faq-a">No. An info_hash is merely a SHA1 digest of the torrent's metadata. The tracker has no information about file contents.</div></div>
<div class="faq-item"><div class="faq-q">Do you keep IP address logs?</div><div class="faq-a">No. We do not keep persistent connection logs. Random IP addresses are inserted into peer lists to protect privacy.</div></div>
<div class="faq-item"><div class="faq-q">What should copyright holders do?</div><div class="faq-a">Since the tracker does not store any files or content, copyright holders should contact the indexing site (e.g., the torrent site), not the tracker. You may, however, submit a report using our form.</div></div>
<div class="faq-item"><div class="faq-q">Do you have .torrent files?</div><div class="faq-a">No. The tracker does not store .torrent files. It only tracks active peer connections.</div></div>
