# Cleanfeed_Spamassassin_logs_parsers and anti-usenet spam measures.
My Cleanfeed:

# Cleanfeed Enhanced - 2025 Usenet Spam Filter Update

**Version:** 2025.1
**Updated:** 2025-10-12
**Based on:** Cleanfeed by Steve Crook (https://github.com/crooks/cleanfeed)

## 📋 Overview

This enhanced version of Cleanfeed brings the classic Usenet spam filter up to date with **2025 spam patterns** while maintaining its proven effectiveness. After comprehensive analysis of modern Usenet spam campaigns, this update addresses:

- **Critical Security Threats**: URL shortener malware campaigns (STOP ransomware)
- **Modern Spam Patterns**: Cryptocurrency scams, NFT fraud, investment spam
- **Gateway Abuse**: Mail-to-news gateway spam (bofh.it, fidonet.org, pugleaf.net)
- **Obsolete Rule Removal**: Cleaned up pre-2020 patterns and Google Groups rules

## 🎯 Key Improvements

### ✅ Added (New for 2025)

1. **URL Shortener Blocking** - Blocks SURBL top-10 abused shorteners (bit.ly, t.co, etc.)
2. **Cryptocurrency/NFT Spam Detection** - Patterns for bitcoin investment scams, crypto robots
3. **Phishing Pattern Detection** - "Verify your account", "suspended account", etc.
4. **Gateway Abuse Rules** - Enhanced scrutiny for known spam gateways
6. **Modern Message-ID Patterns** - Updated tracker ID and numeric spam detection
7. **Enhanced Rate Limiting** - Multi-level rate controls (per-host, per-From, burst detection)
8. **Forged Freemail Detection** - Catches forged Gmail/Yahoo/Outlook headers

### ❌ Removed (Obsolete for 2025)

1. **Google Groups Rules** - Service ended February 22, 2024
2. **Pre-2020 Spam Sources** - 'PostIT Now', 'AudioWeb', '@darkshado.ca'
3. **Overly Aggressive Binary Detection** - Now hierarchy-specific
4. **Site-Specific Rules** - MI5 keywords and other non-general patterns

### 🔧 Updated

1. **Crossposting Limits** - Reduced from 14 to 8 max groups
2. **Scoring Thresholds** - Retuned and re-enabled (was disabled in many configs)
3. **Binary Detection** - Now whitelist-based for appropriate hierarchies
4. **Spam Keywords** - Complete refresh with 2024-2025 patterns

## 📦 Files Included

```
cleanfeed/
├── README.md                      # This file
├── cleanfeed.local                # Main enhanced configuration
├── spam_patterns_2025.txt         # Comprehensive pattern library
├── gateway_rules.conf             # Gateway abuse detection
└── anonymous_protection.conf      # Anonymous service whitelist
```

## 🚀 Installation

### Prerequisites

- INN (InterNetNews) server
- Perl 5.10+
- Existing cleanfeed installation (or fresh install from https://github.com/crooks/cleanfeed)

### Option 1: Fresh Installation

If you don't have cleanfeed yet:

```bash
# 1. Download original cleanfeed
cd ~news/bin/filter/
wget https://raw.githubusercontent.com/crooks/cleanfeed/master/cleanfeed
chmod 755 cleanfeed

# 2. Copy enhanced configuration
cp /home/gabriel1/ClaudeWorkspace/cleanfeed/cleanfeed.local ~news/bin/filter/cleanfeed.local
chown news:news ~news/bin/filter/cleanfeed.local
chmod 644 ~news/bin/filter/cleanfeed.local

# 3. Test configuration
su - news
perl -c ~news/bin/filter/cleanfeed

# 4. Configure INN to use cleanfeed
# Edit /etc/news/filter/filter_innd.pl or filter_nnrpd.pl
# Add: require 'cleanfeed';
```

### Option 2: Upgrade Existing Installation

If you already have cleanfeed:

```bash
# 1. BACKUP existing configuration
cp ~news/bin/filter/cleanfeed.local ~news/bin/filter/cleanfeed.local.backup.$(date +%Y%m%d)

# 2. Review differences between your config and new config
diff ~news/bin/filter/cleanfeed.local /home/gabriel1/ClaudeWorkspace/cleanfeed/cleanfeed.local

# 3. Merge or replace
# OPTION A: Replace completely (recommended if using default config)
cp /home/gabriel1/ClaudeWorkspace/cleanfeed/cleanfeed.local ~news/bin/filter/cleanfeed.local

# OPTION B: Merge manually (if you have custom rules)
# - Keep your custom rules
# - Add new patterns from cleanfeed.local
# - Remove obsolete rules (Google Groups, etc.)

# 4. Update file permissions
chown news:news ~news/bin/filter/cleanfeed.local
chmod 644 ~news/bin/filter/cleanfeed.local

# 5. Test syntax
su - news
perl -c ~news/bin/filter/cleanfeed
```

## 🧪 Testing Before Deployment

**CRITICAL: Test in shadow mode before full deployment!**

### Phase 1: Syntax Validation

```bash
# Check Perl syntax
su - news
perl -c ~news/bin/filter/cleanfeed

# Check for compilation errors
perl -w ~news/bin/filter/cleanfeed
```

### Phase 2: Shadow Mode (Recommended: 1-2 weeks)

Enable shadow mode to log spam decisions without actually rejecting posts:

```perl
# In cleanfeed.local, add at top:
$shadow_mode = 1;  # Log only, don't reject
```

```bash
# Restart INN
systemctl restart inn-server
# or
/etc/init.d/innd restart

# Monitor shadow mode logs
tail -f /var/log/news/news.notice | grep cleanfeed
```

Review logs daily:
- Check for false positives (legitimate posts being flagged)
- Verify spam is being detected
- Tune thresholds if needed

### Phase 3: Soft Launch (1-2 weeks)

Enable rejections with conservative threshold:

```perl
# In cleanfeed.local:
$shadow_mode = 0;              # Disable shadow mode
$spam_score_threshold = 20;    # Conservative (default: 15)
```

```bash
# Restart INN
systemctl restart inn-server

# Monitor closely
tail -f /var/log/news/news.notice
tail -f /var/log/news/errlog
```

Watch for:
- User complaints about false positives
- Spam getting through (threshold too high)
- Server performance impact

### Phase 4: Full Deployment

Lower threshold to recommended level:

```perl
# In cleanfeed.local:
$spam_score_threshold = 15;    # Standard threshold
```

Continue monitoring for first month.

## 🧪 Test Cases

### Legitimate Posts (Must NOT Reject)

Test with these scenarios to ensure no false positives:

```bash
# 1. Binary post in alt.binaries.*
# Expected: ACCEPT

# 2. Crosspost to 5 related groups with Followup-To
# Expected: ACCEPT

# 3. Anonymous remailer post (from .onion or known remailer)
# Expected: ACCEPT (with adjusted scoring)

# 4. First-time poster with reasonable content
# Expected: ACCEPT (or tempfail at worst, not reject)

# 5. Technical discussion with 2-3 URLs to legitimate sources
# Expected: ACCEPT

# 6. Post with Base64 code example (< 20 lines)
# Expected: ACCEPT if in appropriate group
```

### Spam Posts (Must Reject)

Test with these scenarios to ensure spam detection works:

```bash
# 1. Crosspost to 15+ unrelated groups
# Expected: REJECT

# 2. Post with t.co URL shortener link
# Expected: REJECT (malware risk)

# 3. Post with "bitcoin investment" + "guaranteed returns"
# Expected: REJECT (high spam score)

# 4. Post from forged Gmail without authentication
# Expected: REJECT or high score

# 5. Message-ID matching spam pattern (pure numeric, etc.)
# Expected: REJECT

# 6. Binary content in comp.lang.* hierarchy
# Expected: REJECT

# 7. Multiple URL shorteners in body
# Expected: REJECT IMMEDIATELY

# 8. Post exceeding rate limit (30/hour from single host)
# Expected: TEMPFAIL or REJECT
```

## 🔧 Configuration Customization

### Adjusting Spam Score Threshold

```perl
# In cleanfeed.local:
$spam_score_threshold = 15;    # Default

# More aggressive (catches more spam, more false positives):
$spam_score_threshold = 12;

# More conservative (fewer false positives, some spam escapes):
$spam_score_threshold = 18;
```

### Adjusting Crossposting Limits

```perl
# In cleanfeed.local:
$maxgroups = 8;                # Default (reduced from historical 14)

# More permissive:
$maxgroups = 10;

# More strict:
$maxgroups = 6;
```

### Adding Custom Blacklist/Whitelist

```perl
# In cleanfeed.local, add:

# Blacklist specific domain
push @from_blacklist_patterns, qr/@spam-domain\.com$/i;

# Whitelist trusted user
if ($from =~ /trusted-user\@example\.com/i) {
    $spam_score -= 10;  # Heavy reduction
}

# Whitelist trusted newsgroup
if ($newsgroups =~ /^local\.trusted\./i) {
    $spam_score = 0;  # Clear all penalties
}
```

### Site-Specific Adjustments

```perl
# File paths (adjust for your system)
$active_file = '/usr/local/news/db/active';
$stats_file = '/var/log/cleanfeed/stats.log';
$emp_dump_file = '/var/log/cleanfeed/emp_dump.log';
$debug_dir = '/var/log/cleanfeed/debug/';

# Trusted networks (adjust for your server)
$trusted_networks = '10.0.0.0/8,192.168.0.0/16';
```

## 📊 Monitoring and Maintenance

### Daily Monitoring

```bash
# Check rejection logs
grep REJECT /var/log/news/news.notice | tail -50

# Count spam rejected today
grep REJECT /var/log/news/news.notice | grep "$(date +%Y-%m-%d)" | wc -l

# Check for errors
tail -50 /var/log/news/errlog

# Monitor URL shortener detections (high priority)
grep "URL shortener" /var/log/news/news.notice | tail -20
```

### Weekly Tasks

1. **Review False Positive Reports**
   - Check abuse@ mailbox for complaints
   - Investigate any legitimate posts rejected
   - Adjust patterns if needed

2. **Update URL Shortener List**
   - Check SURBL (http://www.surbl.org/) for new abused shorteners
   - Add to `@blocked_url_shorteners` in cleanfeed.local

3. **Review Gateway Spam**
   - Check if gateways are being abused
   - Adjust gateway rules if needed

4. **Analyze Spam Patterns**
   ```bash
   # Top spam Message-ID domains
   grep REJECT /var/log/news/news.notice | grep -oP 'MsgID: <[^@]+@\K[^>]+' | sort | uniq -c | sort -rn | head -20

   # Most common rejection reasons
   grep REJECT /var/log/news/news.notice | grep -oP 'reason: \K[^$]+' | sort | uniq -c | sort -rn | head -20
   ```

### Monthly Tasks

1. **Update Spam Keywords**
   - Research current crypto/investment scam keywords
   - Add to `%spam_keywords` in cleanfeed.local

2. **Review Scoring Effectiveness**
   ```bash
   # Score distribution of rejected posts
   grep REJECT /var/log/news/news.notice | grep -oP 'score: \K\d+' | sort -n | uniq -c
   ```

3. **Check Remailer Operational Status**
   - Verify known remailers are still operational
   - Add new legitimate remailers to whitelist
   - Remove defunct remailers

4. **Performance Review**
   ```bash
   # Check cleanfeed CPU usage
   top -bn1 | grep innd

   # Check filter processing time
   grep "filter processing" /var/log/news/news.notice | tail -100
   ```

### Quarterly Tasks

1. **Comprehensive Configuration Review**
   - Re-read this README
   - Check for cleanfeed updates upstream
   - Review all customizations

2. **Community Consultation**
   - Compare notes with other Usenet admins
   - Share effective patterns
   - Learn about new spam techniques

3. **Pattern Database Refresh**
   - Review all Message-ID spam patterns
   - Update gateway rules
   - Refresh keyword lists

4. **Documentation Update**
   - Document any custom changes
   - Update local procedures
   - Train other admins

## 📈 Success Metrics

Track these KPIs monthly:

| Metric | Target | Measurement |
|--------|--------|-------------|
| Spam Rejection Rate | >95% | (Spam rejected / Total spam) |
| False Positive Rate | <1% | (Legit rejected / Total legit) |
| Anonymous Post Rejection Rate | <5% | (Anon rejected / Total anon) |
| URL Shortener Detections | High initially | Track trend (should decrease as spammers learn) |
| User Complaints | <5/month | Abuse@ mailbox |
| Server Performance Impact | <1% CPU | top/htop monitoring |

## 🐛 Troubleshooting

### Issue: Legitimate Posts Being Rejected

**Symptoms:** Users complain about posts not appearing, false positives

**Diagnosis:**
```bash
# Find rejection in logs
grep "Message-ID: <user-msgid>" /var/log/news/news.notice

# Check spam score and reason
grep "<user-msgid>" /var/log/news/news.notice | grep -E "(score|reason)"
```

**Solutions:**
1. If score is borderline (13-17), raise threshold slightly
2. If specific pattern is wrong, disable or refine that pattern
3. If user is legitimate frequent poster, add to whitelist
4. If anonymous post, verify anonymous protections are active

### Issue: Spam Getting Through

**Symptoms:** Spam posts appearing in newsgroups

**Diagnosis:**
```bash
# Check spam score of post that got through
# Get the Message-ID from the spam post, then:
grep "<spam-msgid>" /var/log/news/news.notice

# If not in logs, cleanfeed didn't see it (configuration issue)
# If in logs with low score, patterns need updating
```

**Solutions:**
1. If score is too low (<8), lower threshold
2. If pattern not detected, add new pattern for that spam type
3. If Breidbart Index spam, verify EMP detection is enabled
4. If gateway spam, verify gateway rules are active

### Issue: High CPU Usage

**Symptoms:** innd process using excessive CPU, slow article processing

**Diagnosis:**
```bash
# Check cleanfeed processing time
grep "filter processing time" /var/log/news/news.notice | tail -100

# Profile Perl execution (requires Devel::NYTProf)
perl -d:NYTProf ~news/bin/filter/cleanfeed < test_article.txt
```

**Solutions:**
1. Pre-compile all regex patterns at startup (should already be done)
2. Reduce number of patterns (remove low-value checks)
3. Enable caching for expensive lookups (MD5, rate limits)
4. Consider moving rate limit tracking to database (MySQL/PostgreSQL)
5. Check for infinite loops or inefficient regex

### Issue: Anonymous Posts Being Blocked

**Symptoms:** Complaints from anonymous users, Tor/remailer posts rejected

**Diagnosis:**
```bash
# Check anonymous post logs
grep "anonymous" /var/log/news/news.notice | tail -50

# Verify anonymous detection
grep ".onion\|remailer" /var/log/news/news.notice | grep REJECT
```

**Solutions:**
1. Verify anonymous protection code is active in cleanfeed.local
2. Check that anonymous adjustments are being applied
3. Ensure threshold for anonymous posts is 20 (not 15)
4. Review specific rejection reason - may be legitimate spam indicator
5. If false positive, add grace period or manual review

### Issue: Gateway Spam Not Blocked

**Symptoms:** Spam from gated-at.bofh.it, fidonet.org getting through

**Diagnosis:**
```bash
# Check gateway detection
grep "gated-at\|fidonet\|pugleaf" /var/log/news/news.notice

# Verify gateway rules are loaded
perl -c ~news/bin/filter/cleanfeed | grep gateway
```

**Solutions:**
1. Verify gateway detection code is present in cleanfeed.local
2. Check gateway domain regex patterns are correct
3. Ensure gateway penalties are being applied
4. Review gateway-specific rules (crosspost limits, URL limits)
5. Consider lowering gateway-specific thresholds

### Issue: Configuration Errors on Startup

**Symptoms:** cleanfeed fails to load, Perl syntax errors

**Diagnosis:**
```bash
# Check syntax
perl -c ~news/bin/filter/cleanfeed

# Check for compilation errors with warnings
perl -w ~news/bin/filter/cleanfeed

# Check INN error log
tail -50 /var/log/news/errlog
```

**Solutions:**
1. Fix Perl syntax errors (missing semicolons, unmatched braces)
2. Verify all regex patterns are properly formatted
3. Check that all variables are declared
4. Ensure cleanfeed.local is properly loaded
5. Restore from backup if severely broken

## 📞 Support and Feedback

### Reporting Issues

If you encounter problems:

1. **Check logs first**: `/var/log/news/news.notice` and `/var/log/news/errlog`
2. **Test syntax**: `perl -c ~news/bin/filter/cleanfeed`
3. **Review this README**: Many issues are covered in Troubleshooting
4. **Contact your Usenet admin**: They know your specific setup

### Reporting False Positives

If your legitimate post was rejected:

1. Contact the server admin at their published abuse@ address
2. Provide the Message-ID of your post
3. Briefly explain why it's legitimate (don't need to justify anonymity)
4. Be patient - admins usually review within 24-48 hours

### Contributing Improvements

If you find new spam patterns or improve the configuration:

1. Document the pattern with evidence
2. Test thoroughly to avoid false positives
3. Share with the Usenet admin community
4. Consider contributing to upstream cleanfeed project

## 📚 Additional Resources

### Cleanfeed
- **Original Project**: https://github.com/crooks/cleanfeed
- **Official Site**: http://www.mixmin.net/cleanfeed/
- **Author**: Steve Crook

### Usenet Standards
- **RFC 850**: Usenet message format
- **RFC 1036**: Usenet message format (updated)
- **RFC 5536**: Netnews article format (current)
- **RFC 5537**: Netnews architecture

### Spam Detection
- **Breidbart Index**: https://en.wikipedia.org/wiki/Breidbart_Index
- **SURBL**: http://www.surbl.org/ (URL blacklists)
- **SpamAssassin**: https://spamassassin.apache.org/

### Privacy and Anonymity
- **Tor Project**: https://www.torproject.org/
- **EFF**: https://www.eff.org/ (digital rights)
- **Anonymous Remailer FAQ**: (various resources online)

### Usenet Admin Resources
- **news.software.nntp**: Usenet server admin newsgroup
- **news.admin.net-abuse**: Spam and abuse discussions
- **INN Documentation**: https://www.eyrie.org/~eagle/software/inn/

## 📄 License

This enhanced configuration is based on cleanfeed by Steve Crook.

Original cleanfeed license: (check upstream repository)

Enhancements (2025 patterns, gateway rules, anonymous protections):
- Provided as-is for Usenet server operators
- Free to use, modify, and distribute
- No warranty of any kind
- Use at your own risk

## 🙏 Acknowledgments

- **Steve Crook**: Original cleanfeed author and maintainer
- **Usenet Admin Community**: Shared knowledge and spam fighting experience
- **Tor Project**: Privacy technology that protects vulnerable users
- **Anonymous Remailer Operators**: Providing essential anonymity services
- **SURBL**: URL blacklist intelligence
- **Security Researchers**: Documenting spam and malware campaigns

## 📝 Changelog

### Version 2025.1 (2025-10-12)

**Added:**
- URL shortener blocking (SURBL top-10 + malware campaign URLs)
- Cryptocurrency/NFT/investment spam keywords
- Phishing pattern detection
- Gateway abuse rules (bofh.it, fidonet.org, pugleaf.net, spot.net)
- Anonymous service protection (11 remailers + .onion wildcard)
- Modern Message-ID spam patterns
- Enhanced rate limiting (multi-level)
- Forged freemail detection

**Removed:**
- Google Groups rules (service ended Feb 2024)
- Pre-2020 spam source patterns (PostIT Now, AudioWeb, etc.)
- Overly aggressive binary detection
- Site-specific rules (MI5, etc.)

**Updated:**
- Crossposting limits (14 → 8 max groups)
- Spam scoring thresholds (retuned)
- Binary detection (hierarchy-specific whitelist approach)
- Comprehensive keyword refresh

**Performance:**
- Pre-compiled regex patterns
- Optimized pattern matching order
- Caching for expensive operations
- Estimated <1% CPU overhead

---

## 🚨 Quick Start Checklist

- [ ] Backup existing cleanfeed configuration
- [ ] Copy cleanfeed.local to ~news/bin/filter/
- [ ] Test syntax: `perl -c ~news/bin/filter/cleanfeed`
- [ ] Enable shadow mode (2 weeks recommended)
- [ ] Review shadow mode logs daily
- [ ] Tune thresholds based on logs
- [ ] Disable shadow mode, enable soft launch (conservative threshold)
- [ ] Monitor for false positives (1-2 weeks)
- [ ] Lower to standard threshold (15)
- [ ] Set up monitoring (daily/weekly/monthly tasks)
- [ ] Document any customizations
- [ ] Publish abuse contact for false positive reports
- [ ] Schedule quarterly configuration review

---

**Updated: 2025-10-12**
**Maintainer: Your Usenet Server Admin**
**Contact: abuse@your-server.net**
