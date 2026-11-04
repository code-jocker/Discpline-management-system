import http.cookiejar
import urllib.parse
import urllib.request
import re

app = 'http://127.0.0.1:8000'
login_url = app + '/auth/login.php'
letters_url = app + '/letters/index.php'

cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj), urllib.request.HTTPRedirectHandler())

resp = opener.open(login_url)
html = resp.read().decode('utf-8', errors='ignore')
match = re.search(r'name="csrf_token" value="([^"]+)"', html)
csrf = match.group(1) if match else ''
print('csrf_token:', csrf)

payload = urllib.parse.urlencode({
    'csrf_token': csrf,
    'email': 'admin@example.com',
    'password': 'admin',
    'remember_me': '1'
}).encode('utf-8')
req = urllib.request.Request(login_url, data=payload, headers={'Content-Type':'application/x-www-form-urlencoded'})
resp = opener.open(req)
print('login url:', resp.geturl())
print('login code:', resp.getcode())
print('cookies:', [(c.name,c.value) for c in cj])

resp = opener.open(letters_url)
letters_html = resp.read().decode('utf-8', errors='ignore')
print('letters url:', resp.geturl())
print('letters code:', resp.getcode())
print('title:', re.search(r'<title>([^<]+)</title>', letters_html).group(1) if re.search(r'<title>([^<]+)</title>', letters_html) else 'N/A')
print('contains letters table:', 'Generated Parent Letters' in letters_html)
print('contains login form:', 'auth/login.php' in letters_html)
