# SSL Certificate Strategy for Wildcard Staging

## The Cloudflare Warning Explained

When you added `*.stg-tiketi.anohou.dev` in Cloudflare, you saw:
> "This hostname is not covered by a certificate. To ensure full coverage, purchase Advanced Certificate Manager..."

This message appears because:
- **Cloudflare's Free Universal SSL** only covers:
  - `stg-tiketi.anohou.dev` ✅
  - `www.stg-tiketi.anohou.dev` ✅
  - **NOT** `*.stg-tiketi.anohou.dev` ❌

## Solution: Disable Cloudflare Proxy for Wildcard

Since Cloudflare's free SSL doesn't cover wildcards, we have two options:

### ✅ Option 1: DNS-Only (Gray Cloud) - RECOMMENDED

**What to Do:**
1. Go to Cloudflare DNS settings
2. Find the `*.stg-tiketi` record
3. Click the **orange cloud** icon to turn it **gray** (DNS only)
4. Repeat for `stg-tiketi` (main domain) if you want Traefik to handle all SSL

**Result:**
- Traffic goes **directly to your server** (not through Cloudflare proxy)
- **Traefik** handles SSL with Let's Encrypt
- Let's Encrypt **supports wildcard certificates** via DNS challenge

**Pros:**
- ✅ Free
- ✅ Traefik fully controls SSL
- ✅ No additional Cloudflare configuration needed

**Cons:**
- ❌ Lose Cloudflare CDN caching
- ❌ Lose Cloudflare DDoS protection

For **staging**, this is perfectly fine. You don't need CDN/DDoS protection for internal testing.

---

### Option 2: Cloudflare Origin Certificate (If Keeping Proxy)

If you **must** keep the orange cloud (proxied):

**What to Do:**
1. Cloudflare Dashboard → SSL/TLS → Origin Server
2. Click **"Create Certificate"**
3. Choose:
   - Hostnames: `*.stg-tiketi.anohou.dev, stg-tiketi.anohou.dev`
   - Validity: 15 years
4. Download:
   - Origin Certificate (`.pem`)
   - Private Key (`.key`)
5. Upload to your server and configure Traefik to use them

**Pros:**
- ✅ Keep Cloudflare proxy benefits
- ✅ Free

**Cons:**
- ❌ More complex setup
- ❌ Certificate only trusted by Cloudflare (not browsers directly)

---

## Recommended Approach for Staging

### Step 1: Disable Cloudflare Proxy (Gray Cloud)

```bash
# In Cloudflare DNS settings:
# 1. Click on *.stg-tiketi → Click orange cloud → Turns gray "DNS only"
# 2. Click on stg-tiketi → Click orange cloud → Turns gray "DNS only"
```

### Step 2: Verify DNS Resolution

Wait 1-2 minutes, then test:
```bash
dig stg-tiketi.anohou.dev +short
# Should show your SERVER IP (not Cloudflare IPs)
```

### Step 3: Ensure Traefik Has Let's Encrypt Configured

Your Traefik should already have Let's Encrypt configured (since it's working for other services).

Verify Traefik config has:
```yaml
certificatesResolvers:
  letsencrypt:
    acme:
      email: your-email@example.com
      storage: /letsencrypt/acme.json
      httpChallenge:
        entryPoint: web
```

### Step 4: Deploy

Run the deploy script:
```bash
cd /Users/wyao/Workspace/1-anohou2/anohou-dev/tiketi
./deployment/deploy.sh staging deploy
```

### Step 5: Monitor Certificate Generation

Watch Traefik logs to see Let's Encrypt certificate generation:
```bash
# On the server
docker logs -f <traefik-container-name>
```

You should see:
```
time="..." level=info msg="Certificates obtained for domains [stg-tiketi.anohou.dev]"
time="..." level=info msg="Certificates obtained for domains [*.stg-tiketi.anohou.dev]"
```

---

## If Traefik Uses HTTP Challenge (Not DNS Challenge)

**Problem**: Let's Encrypt **requires DNS challenge** for wildcard certificates. HTTP challenge doesn't work.

**If you see an error like:**
```
Cannot obtain wildcard certificate with HTTP challenge
```

**Solution**: Configure Traefik for DNS challenge with Cloudflare API.

### Quick Traefik DNS Challenge Setup

1. Get Cloudflare API Token:
   - Cloudflare Dashboard → My Profile → API Tokens
   - Create Token → Use "Edit zone DNS" template
   - Permissions: `Zone:DNS:Edit` for `stg-tiketi.anohou.dev`

2. Update Traefik config:
```yaml
certificatesResolvers:
  letsencrypt:
    acme:
      email: your-email@example.com
      storage: /letsencrypt/acme.json
      dnsChallenge:
        provider: cloudflare
        resolvers:
          - "1.1.1.1:53"
          - "8.8.8.8:53"
```

3. Add environment to Traefik container:
```yaml
environment:
  - CF_API_EMAIL=your-email@example.com
  - CF_DNS_API_TOKEN=your-cloudflare-api-token
```

4. Restart Traefik

---

## Testing After Deployment

### 1. Test Central Domain
```bash
curl -I https://stg-tiketi.anohou.dev
# Should return 200 OK with valid SSL
```

### 2. Test Wildcard Subdomain
```bash
curl -I https://test.stg-tiketi.anohou.dev/login
# Should return 200 OK with valid SSL
```

### 3. Browser Test
- Go to `https://stg-tiketi.anohou.dev/landlord/tenants`
- Create a tenant (e.g., `alpha`)
- Access `https://alpha.stg-tiketi.anohou.dev/login`
- Check browser for SSL padlock ✅

---

## Quick Decision Guide

**Choose THIS if:**| Gray Cloud (DNS Only) | Orange Cloud (Proxied) |
|---|---|
| ✅ Staging environment | ❌ Need CDN caching |
| ✅ Simple setup | ❌ Need DDoS protection |
| ✅ Let Traefik handle SSL | ✅ Already have Origin Cert |
| ✅ Free wildcard cert | ❌ Willing to configure DNS challenge |

**For staging, I strongly recommend: Gray Cloud (DNS Only)**

---

## Next Steps

1. **Turn DNS to gray cloud** in Cloudflare
2. **Wait 2 minutes** for DNS propagation
3. **Run deployment**: `./deployment/deploy.sh staging deploy`
4. **Monitor Traefik logs** for certificate generation
5. **Test** both central and tenant domains

---

**Created**: 2026-01-04
**Recommended**: Gray Cloud + Traefik Let's Encrypt HTTP Challenge
